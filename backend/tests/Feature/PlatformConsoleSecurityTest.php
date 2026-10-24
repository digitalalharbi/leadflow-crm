<?php

namespace Tests\Feature;

use App\Enums\IntegrationProvider;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The platform owner's door, second factor and support access — and the line between the platform and a company that
 * none of them may blur.
 */
class PlatformConsoleSecurityTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    private array $b;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->provisionTenant('Alpha Realty');
        $this->b = $this->provisionTenant('Beta Realty');
        $this->owner = $this->makePlatformOwner();
    }

    private function platformLogin(array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/platform/auth/login', array_merge(['email' => 'owner@platform.test', 'password' => 'password123'], $extra));
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_a_companys_login_never_signs_in_the_platform_owner(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'owner@platform.test', 'password' => 'password123'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_the_platform_login_never_signs_in_a_company_user(): void
    {
        $this->postJson('/api/v1/platform/auth/login', ['email' => $this->a['admin']->email, 'password' => 'password123'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertTrue(PlatformAuditLog::where('action', 'platform.login_failed')->exists());
    }

    public function test_only_a_platform_session_opens_the_console(): void
    {
        $token = $this->platformLogin()->assertOk()->json('data.token');
        $this->withHeaders($this->bearer($token))->getJson('/api/v1/super-admin/stats')->assertOk();

        // A company admin's real session is refused, with the same answer as a page that does not exist.
        $tenantToken = $this->postJson('/api/v1/auth/login', ['email' => $this->a['admin']->email, 'password' => 'password123'])->json('data.token');
        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->bearer($tenantToken))->getJson('/api/v1/super-admin/stats')->assertNotFound();
    }

    public function test_the_second_factor_is_enrolled_confirmed_and_then_required(): void
    {
        $token = $this->platformLogin()->json('data.token');

        $secret = $this->withHeaders($this->bearer($token))->postJson('/api/v1/platform/auth/mfa/setup')->assertOk()->json('data.secret');
        $this->withHeaders($this->bearer($token))->postJson('/api/v1/platform/auth/mfa/confirm', ['code' => '000000'])->assertUnprocessable();

        $confirmed = $this->withHeaders($this->bearer($token))->postJson('/api/v1/platform/auth/mfa/confirm', ['code' => Totp::code($secret)])->assertOk();
        $recovery = $confirmed->json('data.recovery_codes');
        $this->assertCount(8, $recovery);

        // The secret is encrypted at rest and never serialised.
        $this->assertNotSame($secret, \DB::table('users')->where('id', $this->owner->id)->value('mfa_secret'));
        $this->assertArrayNotHasKey('mfa_secret', $this->owner->fresh()->toArray());

        $this->app['auth']->forgetGuards();
        $this->platformLogin()->assertOk()->assertJsonPath('data.mfa_required', true)->assertJsonMissingPath('data.token');
        $this->platformLogin(['code' => '123456'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->platformLogin(['code' => Totp::code($secret)])->assertOk()->assertJsonStructure(['data' => ['token']]);

        // A recovery code works once. (A minute on, so the sign-in limiter — which is real here — has reset.)
        $this->travel(61)->seconds();
        $this->platformLogin(['code' => $recovery[0]])->assertOk();
        $this->platformLogin(['code' => $recovery[0]])->assertUnprocessable();

        $this->assertTrue(PlatformAuditLog::where('action', 'platform.mfa_enabled')->exists());
    }

    public function test_a_required_second_factor_leaves_an_unenrolled_owner_able_only_to_enrol(): void
    {
        config(['platform.require_mfa' => true]);

        $login = $this->platformLogin()->assertOk()->assertJsonPath('data.mfa_enrolment_required', true);
        $token = $login->json('data.token');

        $this->withHeaders($this->bearer($token))->getJson('/api/v1/super-admin/stats')->assertNotFound();
        $this->withHeaders($this->bearer($token))->getJson('/api/v1/platform/auth/me')->assertOk()->assertJsonPath('data.mfa_enrolment_required', true);
        $this->withHeaders($this->bearer($token))->postJson('/api/v1/platform/auth/mfa/setup')->assertOk();
    }

    public function test_support_access_is_explicit_scoped_to_one_company_and_on_both_trails(): void
    {
        $leadA = $this->asTenant($this->a['tenant'], fn () => Lead::create(['full_name' => 'Alpha Lead', 'phone' => '0511111111', 'phone_normalized' => '+966511111111']));
        $this->asTenant($this->b['tenant'], fn () => Lead::create(['full_name' => 'Beta Lead', 'phone' => '0522222222', 'phone_normalized' => '+966522222222']));

        // A reason is required.
        $this->actingAs($this->owner)->postJson("/api/v1/super-admin/tenants/{$this->a['tenant']->uuid}/impersonate", [])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $started = $this->actingAs($this->owner)->postJson("/api/v1/super-admin/tenants/{$this->a['tenant']->uuid}/impersonate", ['reason' => 'Ticket 42: import help'])
            ->assertCreated()->assertJsonPath('data.session.as.email', $this->a['admin']->email);
        $token = $started->json('data.token');
        $this->app['auth']->forgetGuards();

        // Inside A, as A's owner — and only A.
        $names = collect($this->withHeaders($this->bearer($token))->getJson('/api/v1/leads')->assertOk()->json('data'))->pluck('full_name');
        $this->assertSame(['Alpha Lead'], $names->all());
        $this->withHeaders($this->bearer($token))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.impersonation.reason', 'Ticket 42: import help');

        // Nothing that belongs to the person or outlives the session.
        $this->withHeaders($this->bearer($token))->postJson('/api/v1/auth/password', ['current_password' => 'x', 'password' => 'newpassword1', 'password_confirmation' => 'newpassword1'])
            ->assertForbidden()->assertJsonPath('errors.support_session.0', 'refused');
        $this->withHeaders($this->bearer($token))->postJson('/api/v1/auth/logout-all')->assertForbidden();
        // It is not a console session either.
        $this->withHeaders($this->bearer($token))->getJson('/api/v1/super-admin/stats')->assertNotFound();

        $this->assertTrue(PlatformAuditLog::where('action', 'impersonation.started')->where('tenant_id', $this->a['tenant']->id)->exists());
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('tenant_id', $this->a['tenant']->id)->where('action', 'support.session_started')->exists());
        $this->assertFalse(AuditLog::withoutGlobalScopes()->where('tenant_id', $this->b['tenant']->id)->where('action', 'like', 'support.%')->exists());

        $this->withHeaders($this->bearer($token))->postJson('/api/v1/auth/impersonation/stop')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->bearer($token))->getJson('/api/v1/leads')->assertUnauthorized();

        $this->assertTrue(PlatformAuditLog::where('action', 'impersonation.ended')->exists());
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('tenant_id', $this->a['tenant']->id)->where('action', 'support.session_ended')->exists());
        $this->assertNotNull($leadA->fresh());
    }

    public function test_a_support_session_ends_on_its_own(): void
    {
        $token = $this->actingAs($this->owner)->postJson("/api/v1/super-admin/tenants/{$this->a['tenant']->uuid}/impersonate", ['reason' => 'Quick look at settings'])->json('data.token');
        $this->app['auth']->forgetGuards();

        $this->travel(config('platform.impersonation_minutes') + 1)->minutes();

        $this->withHeaders($this->bearer($token))->getJson('/api/v1/leads')->assertUnauthorized();
    }

    public function test_the_owner_stays_an_active_admin_until_the_platform_transfers_it(): void
    {
        $tenant = $this->a['tenant']->fresh();
        $this->assertSame($this->a['admin']->id, $tenant->owner_user_id);
        $second = $this->makeUser($tenant, Role::Admin);

        foreach ([['role' => 'manager'], ['is_active' => false]] as $change) {
            $this->actingAs($second)->patchJson("/api/v1/users/{$this->a['admin']->uuid}", $change)->assertUnprocessable();
        }
        $this->actingAs($second)->deleteJson("/api/v1/users/{$this->a['admin']->uuid}")->assertUnprocessable();
        $this->assertTrue($this->a['admin']->fresh()->is_active);

        $this->actingAs($this->owner)->postJson("/api/v1/super-admin/tenants/{$tenant->uuid}/owner", ['user_id' => $second->uuid])
            ->assertOk()->assertJsonPath('data.tenant.owner.email', $second->email);

        // Company B's people cannot be made A's owner.
        $this->actingAs($this->owner)->postJson("/api/v1/super-admin/tenants/{$tenant->uuid}/owner", ['user_id' => $this->b['admin']->uuid])->assertNotFound();

        $this->assertTrue(PlatformAuditLog::where('action', 'tenant.owner_transferred')->exists());
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('action', 'workspace.owner_changed')->exists());
    }

    public function test_the_console_shows_integration_health_never_a_credential(): void
    {
        $this->asTenant($this->a['tenant'], fn () => Integration::create([
            'provider' => IntegrationProvider::GenericWebhook, 'name' => 'Secretive',
            'status' => Integration::CONNECTED, 'credentials' => ['api_key' => 'sk_live_TOPSECRET123'], 'field_mapping' => [],
        ]));

        $response = $this->actingAs($this->owner)->getJson("/api/v1/super-admin/tenants/{$this->a['tenant']->uuid}")->assertOk();

        $this->assertStringNotContainsString('TOPSECRET', $response->getContent());
        $this->assertTrue(collect($response->json('data.integrations'))->firstWhere('name', 'Secretive')['has_credentials']);
    }
}
