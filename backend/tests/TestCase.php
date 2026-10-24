<?php

namespace Tests;

use App\Domain\Access\AccessResolver;
use App\Domain\Automation\JourneyInstaller;
use App\Domain\Automation\Thresholds;
use App\Domain\Mail\MailerRegistry;
use App\Enums\Role;
use App\Models\AutomationRule;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Mockery;
use Tests\Support\FakeMailer;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Static caches must not outlive the app they were read in.
        Thresholds::flush();
        AccessResolver::flush();

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

    /**
     * This process as a production deployment: told it is production, at an
     * HTTPS address on a public name. The integration ladder will not say
     * "live" for anything less, so a test that asserts live runs here.
     */
    protected function asProductionDeployment(): void
    {
        $this->app['env'] = 'production';
        config(['app.url' => 'https://app.muhtam-production.example.sa']);
    }

    /**
     * A workspace without the lead journey every workspace now starts with.
     *
     * For tests about the rule engine's own mechanics — ordering, delays,
     * tenancy, the trace — which read "the rules in this workspace" and are
     * about the rules they write themselves, not the ones installed for them.
     */
    protected function withoutJourney(Tenant $tenant): void
    {
        $this->asTenant($tenant, fn () => AutomationRule::whereIn('template_key', JourneyInstaller::RULES)->forceDelete());
    }

    /**
     * The product's one mail door, replaced by a provider the test can read: every email that any
     * path sends — a report, a digest, an alert, a lead's message — lands in `$fake->sent`.
     */
    protected function fakeMailer(bool $delivers = false): FakeMailer
    {
        $fake = new FakeMailer('fake', $delivers);
        $registry = Mockery::mock(MailerRegistry::class);
        $registry->shouldReceive('current')->andReturn($fake);
        $registry->shouldReceive('get')->andReturn($fake);
        $registry->shouldReceive('all')->andReturn([$fake]);
        $this->app->instance(MailerRegistry::class, $registry);

        return $fake;
    }

    /** The platform owner: no tenant, platform-level authority. */
    protected function makePlatformOwner(string $email = 'owner@platform.test'): User
    {
        return User::create([
            'tenant_id' => null,
            'name' => 'Platform Owner',
            'email' => $email,
            'password' => 'password123',
            'role' => Role::SuperAdmin,
        ]);
    }

    /** Moves a company onto a catalogue plan by key, as the console would. */
    protected function putOnPlan(Tenant $tenant, string $key, array $attributes = []): Tenant
    {
        $plan = Plan::where('key', $key)->firstOrFail();
        $tenant->forceFill(array_merge(['plan_id' => $plan->id, 'plan' => $plan->key], $attributes))->save();

        return $tenant->fresh();
    }

    /** Runs a closure with the tenant context bound, for direct model work in tests. */
    protected function asTenant(Tenant $tenant, callable $callback): mixed
    {
        return app(TenantContext::class)->withTenant($tenant, $callback);
    }
}
