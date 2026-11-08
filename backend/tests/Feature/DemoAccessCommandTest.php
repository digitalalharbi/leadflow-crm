<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoAccessCommandTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(string $slug, string $email): Tenant
    {
        return app(TenantProvisioningService::class)->provision([
            'company_name' => 'Co '.$slug, 'subdomain' => $slug, 'admin_name' => 'A', 'admin_email' => $email,
        ])['tenant'];
    }

    public function test_it_sets_the_demo_password_and_touches_no_other_workspace(): void
    {
        $demo = $this->workspace('mohtam-demo', 'admin@leadflow-demo.test');
        $other = $this->workspace('other-co', 'owner@other-co.example');

        $this->artisan('mohtam:demo-access')
            ->expectsQuestion('Demo password (at least 12 characters)', 'a-long-demo-password')
            ->expectsQuestion('Type it again', 'a-long-demo-password')
            ->assertSuccessful();

        $ctx = app(TenantContext::class);
        $demoUser = $ctx->withoutScope(fn () => User::where('tenant_id', $demo->id)->first());
        $otherUser = $ctx->withoutScope(fn () => User::where('tenant_id', $other->id)->first());

        $this->assertTrue(Hash::check('a-long-demo-password', $demoUser->password));
        $this->assertFalse(Hash::check('a-long-demo-password', $otherUser->password ?? ''));
    }

    public function test_a_short_or_mismatched_password_changes_nothing(): void
    {
        $this->workspace('mohtam-demo', 'admin@leadflow-demo.test');

        $this->artisan('mohtam:demo-access')
            ->expectsQuestion('Demo password (at least 12 characters)', 'short')
            ->expectsQuestion('Type it again', 'short')
            ->assertFailed();
    }

    public function test_without_a_demo_workspace_it_refuses(): void
    {
        $this->artisan('mohtam:demo-access')->assertFailed();
    }
}
