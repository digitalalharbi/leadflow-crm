<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'tenant_id' => null,
            'name' => 'Platform Owner',
            'email' => 'owner@platform.test',
            'password' => 'password123',
            'role' => Role::SuperAdmin,
        ]);
    }

    public function test_creating_a_client_provisions_everything_in_one_call(): void
    {
        $response = $this->actingAs($this->owner)->postJson('/api/v1/super-admin/tenants', [
            'company_name' => 'ABC Development',
            'system_name' => 'ABC Sales CRM',
            'subdomain' => 'abc',
            'primary_color' => '#aa00aa',
            'admin_name' => 'ABC Admin',
            'admin_email' => 'admin@abc.test',
            'admin_password' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.tenant.slug', 'abc')
            ->assertJsonPath('data.tenant.system_name', 'ABC Sales CRM')
            ->assertJsonStructure(['data' => ['webhook' => ['url', 'secret']]]);

        $this->assertDatabaseHas('tenants', ['slug' => 'abc']);
        $this->assertDatabaseHas('users', ['email' => 'admin@abc.test', 'role' => 'admin']);
        $this->assertDatabaseCount('lost_reasons', 10);

        // And the new admin can sign straight in — no further setup.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@abc.test', 'password' => 'password123',
        ])->assertOk()->assertJsonPath('data.branding.system_name', 'ABC Sales CRM');
    }

    public function test_a_reserved_subdomain_is_refused(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/super-admin/tenants', [
            'company_name' => 'Api Co', 'subdomain' => 'api',
            'admin_name' => 'A', 'admin_email' => 'a@api.test', 'admin_password' => 'password123',
        ])->assertStatus(422)->assertJsonValidationErrors('subdomain');
    }

    public function test_two_tenants_can_reuse_the_same_admin_email(): void
    {
        // Emails are unique per tenant, not globally: one consultant may admin
        // several agencies.
        foreach (['one', 'two'] as $slug) {
            $this->actingAs($this->owner)->postJson('/api/v1/super-admin/tenants', [
                'company_name' => ucfirst($slug).' Co',
                'subdomain' => $slug,
                'admin_name' => 'Shared Admin',
                'admin_email' => 'shared@consultant.test',
                'admin_password' => 'password123',
            ])->assertCreated();
        }

        $this->assertSame(2, User::where('email', 'shared@consultant.test')->count());

        // Logging in must then be disambiguated by subdomain.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'shared@consultant.test', 'password' => 'password123', 'tenant' => 'two',
        ])->assertOk()->assertJsonPath('data.branding.slug', 'two');
    }

    public function test_the_platform_stats_span_every_tenant(): void
    {
        $a = $this->provisionTenant('Alpha');
        $b = $this->provisionTenant('Beta');

        foreach ([$a, $b] as $i => $t) {
            $this->asTenant($t['tenant'], fn () => Lead::create([
                'full_name' => 'L', 'phone_normalized' => '+96650000000'.$i,
            ]));
        }

        $stats = $this->actingAs($this->owner)->getJson('/api/v1/super-admin/stats')->assertOk()->json('data');

        $this->assertSame(2, $stats['tenants']);
        $this->assertSame(2, $stats['leads']);
    }

    public function test_suspending_a_tenant_revokes_its_users_sessions(): void
    {
        $t = $this->provisionTenant('Suspend Me');
        $token = $t['admin']->createToken('device')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/leads')->assertOk();
        $this->app['auth']->forgetGuards();

        $this->actingAs($this->owner)
            ->patchJson("/api/v1/super-admin/tenants/{$t['tenant']->uuid}", ['is_active' => false])
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/leads')->assertUnauthorized();
    }

    public function test_the_owner_can_rebrand_any_tenant(): void
    {
        $t = $this->provisionTenant('Old Name');

        $this->actingAs($this->owner)
            ->patchJson("/api/v1/super-admin/tenants/{$t['tenant']->uuid}", [
                'branding' => ['system_name' => 'Rebranded CRM', 'primary_color' => '#123456'],
            ])->assertOk();

        $this->assertSame('Rebranded CRM',
            $this->getJson('/api/v1/branding?tenant='.$t['tenant']->slug)->json('data.system_name'));
    }

    public function test_the_owner_does_not_get_a_tenant_workspace(): void
    {
        // A super admin has no tenant, so the workspace endpoints must not
        // silently return a global view.
        $this->provisionTenant('Someone Else');

        $this->actingAs($this->owner)->getJson('/api/v1/leads')->assertForbidden();
    }

    public function test_slugs_are_made_unique_automatically(): void
    {
        foreach (range(1, 2) as $i) {
            $this->actingAs($this->owner)->postJson('/api/v1/super-admin/tenants', [
                'company_name' => 'Duplicate Name',
                'admin_name' => 'A',
                'admin_email' => "a{$i}@dup.test",
                'admin_password' => 'password123',
            ])->assertCreated();
        }

        $slugs = Tenant::pluck('slug');

        $this->assertSame($slugs->unique()->count(), $slugs->count());
    }
}
