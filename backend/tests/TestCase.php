<?php

namespace Tests;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Each test starts with no tenant bound; middleware or the helper sets it.
        app(TenantContext::class)->clear();
    }

    /**
     * Creates a fully provisioned tenant the same way the product does, so tests
     * exercise the real onboarding path rather than hand-built fixtures.
     *
     * @return array{tenant: Tenant, admin: User, webhook_key: string, webhook_secret: string}
     */
    protected function provisionTenant(string $company = 'Acme Realty', array $overrides = []): array
    {
        $slug = str()->slug($company).'-'.str()->lower(str()->random(5));

        return app(TenantProvisioningService::class)->provision(array_merge([
            'company_name' => $company,
            'system_name' => $company.' CRM',
            'subdomain' => $slug,
            'admin_name' => 'Admin '.$company,
            'admin_email' => 'admin@'.$slug.'.test',
            'admin_password' => 'password123',
        ], $overrides));
    }

    protected function makeUser(Tenant $tenant, Role $role = Role::Sales, array $attributes = []): User
    {
        return User::create(array_merge([
            'tenant_id' => $tenant->id,
            'name' => $role->label().' '.str()->random(4),
            'email' => str()->lower(str()->random(10)).'@example.test',
            'password' => 'password123',
            'role' => $role,
            'is_active' => true,
        ], $attributes));
    }

    /** Runs a closure with the tenant context bound, for direct model work in tests. */
    protected function asTenant(Tenant $tenant, callable $callback): mixed
    {
        return app(TenantContext::class)->withTenant($tenant, $callback);
    }
}
