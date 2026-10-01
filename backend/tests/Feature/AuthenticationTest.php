<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_sign_in_and_receives_branding_with_the_token(): void
    {
        $t = $this->provisionTenant('Nova Realty');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $t['admin']->email,
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'role'], 'branding' => ['system_name', 'primary_color']]]);

        // The SPA must be able to render as the customer's own product immediately.
        $this->assertSame('Nova Realty CRM', $response->json('data.branding.system_name'));
    }

    public function test_the_login_error_is_identical_for_an_unknown_email_and_a_wrong_password(): void
    {
        $t = $this->provisionTenant();

        $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@nowhere.test', 'password' => 'password123']);
        $wrong = $this->postJson('/api/v1/auth/login', ['email' => $t['admin']->email, 'password' => 'wrong-password']);

        $unknown->assertStatus(422);
        $wrong->assertStatus(422);

        // Account enumeration guard.
        $this->assertSame($unknown->json('errors'), $wrong->json('errors'));
    }

    public function test_a_deactivated_user_cannot_sign_in(): void
    {
        $t = $this->provisionTenant();
        $t['admin']->update(['is_active' => false]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $t['admin']->email,
            'password' => 'password123',
        ])->assertStatus(422)->assertJsonPath('errors.email.0', 'This account has been deactivated.');
    }

    public function test_a_user_of_a_suspended_tenant_cannot_sign_in(): void
    {
        $t = $this->provisionTenant();
        $t['tenant']->update(['is_active' => false]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $t['admin']->email,
            'password' => 'password123',
        ])->assertStatus(422);
    }

    public function test_protected_endpoints_reject_an_anonymous_request(): void
    {
        foreach (['/api/v1/leads', '/api/v1/dashboard', '/api/v1/settings', '/api/v1/users'] as $route) {
            $this->getJson($route)->assertUnauthorized()->assertJsonPath('success', false);
        }
    }

    public function test_logging_out_revokes_only_the_current_token(): void
    {
        $t = $this->provisionTenant();
        $user = $t['admin'];

        $first = $user->createToken('device-1')->plainTextToken;
        $second = $user->createToken('device-2')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$first)
            ->postJson('/api/v1/auth/logout')->assertOk();

        // Laravel caches the resolved guard inside a single test method; a real
        // browser gets a fresh process per request.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$first)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$second)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_changing_a_password_revokes_every_other_session(): void
    {
        $t = $this->provisionTenant();
        $user = $t['admin'];

        $other = $user->createToken('other-device')->plainTextToken;
        $current = $user->createToken('this-device')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$current)
            ->postJson('/api/v1/auth/password', [
                'current_password' => 'password123',
                'password' => 'new-password-456',
                'password_confirmation' => 'new-password-456',
            ])->assertOk();

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$other)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$current)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_a_wrong_current_password_is_rejected(): void
    {
        $t = $this->provisionTenant();

        $this->actingAs($t['admin'])->postJson('/api/v1/auth/password', [
            'current_password' => 'not-it',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertStatus(422);
    }

    public function test_login_is_rate_limited(): void
    {
        RateLimiter::clear('');
        $t = $this->provisionTenant();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $t['admin']->email, 'password' => 'wrong']);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => $t['admin']->email,
            'password' => 'password123',
        ])->assertStatus(429);
    }

    public function test_a_suspended_tenant_is_locked_out_mid_session(): void
    {
        $t = $this->provisionTenant();

        $this->actingAs($t['admin'])->getJson('/api/v1/leads')->assertOk();

        $t['tenant']->update(['is_active' => false]);

        $this->actingAs($t['admin'])->getJson('/api/v1/leads')
            ->assertStatus(403)
            ->assertJsonPath('errors.tenant.0', 'suspended');
    }

    public function test_an_expired_subscription_returns_402(): void
    {
        $t = $this->provisionTenant();
        $t['tenant']->update(['subscription_status' => 'trialing', 'trial_ends_at' => now()->subDay()]);

        $this->actingAs($t['admin'])->getJson('/api/v1/leads')
            ->assertStatus(402)
            ->assertJsonPath('errors.tenant.0', 'subscription_expired');
    }

    public function test_a_super_admin_has_no_tenant_workspace_but_owns_the_console(): void
    {
        $superAdmin = \App\Models\User::create([
            'tenant_id' => null,
            'name' => 'Platform Owner',
            'email' => 'owner@platform.test',
            'password' => 'password123',
            'role' => Role::SuperAdmin,
        ]);

        $this->actingAs($superAdmin)->getJson('/api/v1/super-admin/tenants')->assertOk();
    }
}
