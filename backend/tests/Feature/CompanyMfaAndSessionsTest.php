<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PasswordLink;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A company member's authenticator-app second factor and their signed-in devices; and the workspace rule that makes
 * the second factor compulsory, enforced at the API.
 */
class CompanyMfaAndSessionsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Alpha');
    }

    private function enrol(User $user): array
    {
        $secret = $this->actingAs($user)->postJson('/api/v1/auth/security/mfa/setup')->assertOk()->json('data.secret');
        $codes = $this->actingAs($user)->postJson('/api/v1/auth/security/mfa/confirm', ['code' => Totp::code($secret)])->assertOk()->json('data.recovery_codes');
        app('auth')->forgetGuards();

        return [$secret, $codes];
    }

    private function login(array $extra = [])
    {
        app('auth')->forgetGuards();

        return $this->postJson('/api/v1/auth/login', ['email' => $this->t['admin']->email, 'password' => 'password123'] + $extra);
    }

    public function test_with_a_second_factor_the_password_alone_opens_no_session(): void
    {
        [$secret] = $this->enrol($this->t['admin']);

        $this->login()->assertOk()->assertJsonPath('data.mfa_required', true)->assertJsonMissingPath('data.token');
        $this->login(['code' => '000000'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->login(['code' => Totp::code($secret)])->assertOk()->assertJsonStructure(['data' => ['token']]);

        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'auth.mfa_failed')->count());
    }

    public function test_an_accepted_code_cannot_be_replayed(): void
    {
        [$secret] = $this->enrol($this->t['admin']);
        $code = Totp::code($secret);

        $this->login(['code' => $code])->assertOk()->assertJsonStructure(['data' => ['token']]);
        $this->login(['code' => $code])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_too_many_wrong_codes_lock_the_account_whatever_the_address(): void
    {
        [$secret] = $this->enrol($this->t['admin']);

        foreach (range(1, 5) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])->login(['code' => '000000'])->assertStatus(422);
        }

        // Even the right code, from a new address, waits out the lockout.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->login(['code' => Totp::code($secret)])->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Too many wrong codes. Try again in 15 minutes.')->assertJsonMissingPath('data.token');

        $this->travel(16)->minutes();
        $this->login(['code' => Totp::code($secret)])->assertOk()->assertJsonStructure(['data' => ['token']]);
    }

    public function test_a_recovery_code_works_exactly_once(): void
    {
        [, $codes] = $this->enrol($this->t['admin']);

        $this->login(['code' => $codes[0]])->assertOk()->assertJsonStructure(['data' => ['token']]);
        $this->login(['code' => $codes[0]])->assertStatus(422);
    }

    public function test_the_secret_and_recovery_codes_never_leave_through_the_api(): void
    {
        $this->enrol($this->t['admin']);
        $me = json_encode($this->actingAs($this->t['admin']->fresh())->getJson('/api/v1/auth/me')->json());
        $security = $this->actingAs($this->t['admin']->fresh())->getJson('/api/v1/auth/security')->assertOk();

        $security->assertJsonPath('data.mfa_enabled', true)->assertJsonPath('data.recovery_codes_left', 8);
        $this->assertStringNotContainsString('mfa_secret', $me);
        $this->assertStringNotContainsString('recovery', $me);
    }

    public function test_a_password_reset_does_not_skip_the_second_factor(): void
    {
        $this->enrol($this->t['admin']);
        [, $token] = PasswordLink::issue($this->t['admin']->email, 'reset');

        $this->postJson('/api/v1/auth/password/reset', ['token' => $token, 'password' => 'a-new-Strong-pass-42', 'password_confirmation' => 'a-new-Strong-pass-42'])
            ->assertOk()->assertJsonPath('data.mfa_required', true)->assertJsonMissingPath('data.token');
    }

    public function test_a_workspace_that_requires_it_blocks_everything_but_enrolment(): void
    {
        $rep = $this->makeUser($this->t['tenant']);
        $this->enrol($this->t['admin']);
        $this->actingAs($this->t['admin']->fresh())->patchJson('/api/v1/settings/security', ['require_mfa' => true])->assertOk();

        $this->actingAs($rep)->getJson('/api/v1/leads')->assertForbidden()->assertJsonPath('data.code', 'mfa_enrolment_required');
        $this->actingAs($rep)->getJson('/api/v1/auth/me')->assertOk();
        $this->actingAs($rep)->postJson('/api/v1/auth/security/mfa/setup')->assertOk();

        $this->enrol($rep->fresh());
        $this->actingAs($rep->fresh())->getJson('/api/v1/leads')->assertOk();
        // And it cannot be switched off while the workspace requires it.
        $this->actingAs($rep->fresh())->postJson('/api/v1/auth/security/mfa/disable', ['password' => 'password123', 'code' => 'x'])->assertStatus(422);
    }

    public function test_an_admin_cannot_require_it_before_turning_on_their_own(): void
    {
        $this->actingAs($this->t['admin'])->patchJson('/api/v1/settings/security', ['require_mfa' => true])->assertStatus(422);
        $this->assertFalse((bool) TenantSetting::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->value('require_mfa'));

        $rep = $this->makeUser($this->t['tenant']);
        $this->actingAs($rep)->patchJson('/api/v1/settings/security', ['require_mfa' => false])->assertForbidden();
    }

    public function test_a_person_sees_their_devices_and_can_end_one_but_not_someone_elses(): void
    {
        $first = $this->login()->json('data.token');
        $this->withHeader('User-Agent', 'Phone Safari')->login();
        app('auth')->forgetGuards();

        $sessions = $this->withToken($first)->getJson('/api/v1/auth/security/sessions')->assertOk()->json('data');
        $this->assertCount(2, $sessions);
        $other = collect($sessions)->firstWhere('current', false);
        $this->assertNotNull($other['ip']);

        $this->withToken($first)->deleteJson("/api/v1/auth/security/sessions/{$other['id']}")->assertOk();
        app('auth')->forgetGuards();
        $this->assertCount(1, $this->withToken($first)->getJson('/api/v1/auth/security/sessions')->json('data'));

        // Another person's session id is not theirs to end.
        $rep = $this->makeUser($this->t['tenant']);
        $repToken = $rep->createToken('spa-x');
        app('auth')->forgetGuards();
        $this->withToken($first)->deleteJson("/api/v1/auth/security/sessions/{$repToken->accessToken->id}")->assertNotFound();
    }
}
