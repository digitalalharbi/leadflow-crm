<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\ProductionDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProductionDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('PRODUCTION_DEMO_SEED_ENABLED');
        putenv('PRODUCTION_DEMO_PASSWORD');

        parent::tearDown();
    }

    public function test_it_creates_an_isolated_workspace_with_every_tenant_role(): void
    {
        $this->app['env'] = 'production';
        putenv('PRODUCTION_DEMO_SEED_ENABLED=true');
        putenv('PRODUCTION_DEMO_PASSWORD=Test-only-password-123!');

        $this->seed(ProductionDemoSeeder::class);

        $tenant = Tenant::where('slug', ProductionDemoSeeder::SLUG)->firstOrFail();
        $users = User::where('tenant_id', $tenant->id)->get();

        $this->assertCount(count(Role::tenantRoles()), $users);
        $this->assertEqualsCanonicalizing(Role::tenantRoles(), $users->pluck('role')->map->value->all());
        $this->assertTrue($users->every(fn (User $user) => str_ends_with($user->email, '@'.ProductionDemoSeeder::EMAIL_DOMAIN)));
        $this->assertSame(64, Lead::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_it_refuses_to_replace_an_existing_demo_workspace(): void
    {
        $this->app['env'] = 'production';
        putenv('PRODUCTION_DEMO_SEED_ENABLED=true');
        putenv('PRODUCTION_DEMO_PASSWORD=Test-only-password-123!');

        $this->seed(ProductionDemoSeeder::class);

        $this->expectException(RuntimeException::class);
        $this->seed(ProductionDemoSeeder::class);
    }
}
