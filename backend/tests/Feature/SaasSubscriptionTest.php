<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\PlatformAuditLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mohtam sold as a subscription: the plan decides the features, the seats and the projects; the lifecycle decides
 * whether the workspace opens; and all of it is enforced by the server, per company.
 */
class SaasSubscriptionTest extends TestCase
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

    public function test_a_new_company_starts_its_trial_on_a_real_catalogue_plan(): void
    {
        $tenant = $this->a['tenant']->fresh();

        $this->assertNotNull($tenant->plan_id);
        $this->assertSame('trialing', $tenant->subscription_status);

        $this->actingAs($this->a['admin'])->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.subscription.status', 'trialing')
            ->assertJsonPath('data.subscription.plan.key', config('tenancy.defaults.plan'))
            ->assertJsonPath('data.subscription.usable', true);
    }

    public function test_a_feature_outside_the_plan_is_refused_by_the_server(): void
    {
        $this->putOnPlan($this->a['tenant'], 'starter');

        foreach (['/api/v1/tracking/links', '/api/v1/tracking/forms', '/api/v1/automation/rules', '/api/v1/tracking/attribution', '/api/v1/report-shares', '/api/v1/integrations/whatsapp'] as $path) {
            $this->actingAs($this->a['admin'])->getJson($path)->assertForbidden()->assertJsonStructure(['errors' => ['feature']]);
        }

        // Company B, on the full plan, is not affected by A's plan.
        $this->actingAs($this->b['admin'])->getJson('/api/v1/tracking/links')->assertOk();
        $this->actingAs($this->b['admin'])->getJson('/api/v1/automation/rules')->assertOk();
    }

    public function test_exports_follow_the_plan(): void
    {
        $this->putOnPlan($this->a['tenant'], 'starter', ['addons' => null]);
        $plan = Plan::where('key', 'starter')->first();
        $plan->update(['features' => []]);

        $this->actingAs($this->a['admin'])->get('/api/v1/leads/export')->assertForbidden();

        $plan->update(['features' => ['exports']]);
        $this->actingAs($this->a['admin'])->get('/api/v1/leads/export')->assertOk();
    }

    public function test_an_add_on_switches_one_feature_on_for_one_company(): void
    {
        $this->putOnPlan($this->a['tenant'], 'starter', ['addons' => ['tracking_hub']]);

        $this->actingAs($this->a['admin'])->getJson('/api/v1/tracking/links')->assertOk();
        $this->actingAs($this->a['admin'])->getJson('/api/v1/automation/rules')->assertForbidden();
        $this->actingAs($this->a['admin'])->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.subscription.features', fn ($f) => in_array('tracking_hub', $f, true) && ! in_array('automations', $f, true));
    }

    public function test_seats_are_a_hard_limit_on_new_people_and_never_remove_anyone(): void
    {
        $tenant = $this->putOnPlan($this->a['tenant'], 'starter'); // 3 included seats
        $this->makeUser($tenant, Role::Sales);
        $this->makeUser($tenant, Role::Manager);

        $this->actingAs($this->a['admin'])->postJson('/api/v1/users', [
            'name' => 'Fourth', 'email' => 'fourth@alpha.test', 'password' => 'password123', 'role' => 'sales',
        ])->assertUnprocessable()->assertJsonValidationErrors('seats');

        // An outside viewer does not take a paid seat.
        $this->actingAs($this->a['admin'])->postJson('/api/v1/users', [
            'name' => 'Client', 'email' => 'client@alpha.test', 'password' => 'password123', 'role' => 'client_viewer',
        ])->assertCreated();

        // Buying a seat makes room.
        $tenant->forceFill(['extra_seats' => 1])->save();
        $this->actingAs($this->a['admin'])->postJson('/api/v1/users', [
            'name' => 'Fourth', 'email' => 'fourth@alpha.test', 'password' => 'password123', 'role' => 'sales',
        ])->assertCreated();

        // Company B's seats are its own.
        $this->actingAs($this->b['admin'])->postJson('/api/v1/users', [
            'name' => 'B Rep', 'email' => 'rep@beta.test', 'password' => 'password123', 'role' => 'sales',
        ])->assertCreated();
    }

    public function test_reactivating_someone_takes_a_seat(): void
    {
        $tenant = $this->putOnPlan($this->a['tenant'], 'starter');
        $this->makeUser($tenant, Role::Sales);
        $idle = $this->makeUser($tenant, Role::Sales, ['is_active' => false]);
        $this->makeUser($tenant, Role::Sales);

        $this->actingAs($this->a['admin'])->patchJson("/api/v1/users/{$idle->uuid}", ['is_active' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('seats');
        $this->assertFalse($idle->fresh()->is_active);
    }

    public function test_projects_follow_the_plan(): void
    {
        $this->putOnPlan($this->a['tenant'], 'starter');

        $this->actingAs($this->a['admin'])->postJson('/api/v1/projects', ['name' => 'First'])->assertCreated();
        $this->actingAs($this->a['admin'])->postJson('/api/v1/projects', ['name' => 'Second'])
            ->assertUnprocessable()->assertJsonValidationErrors('project');

        $this->assertSame(1, $this->asTenant($this->a['tenant'], fn () => Project::count()));
    }

    public function test_a_suspended_company_keeps_its_data_and_sees_why(): void
    {
        $this->actingAs($this->a['admin'])->postJson('/api/v1/projects', ['name' => 'Kept'])->assertCreated();

        $this->actingAs($this->owner)->postJson("/api/v1/super-admin/tenants/{$this->a['tenant']->uuid}/subscription/suspend", ['reason' => 'Unpaid'])
            ->assertOk()->assertJsonPath('data.subscription.state', 'suspended');

        $this->actingAs($this->a['admin'])->getJson('/api/v1/leads')->assertStatus(402)
            ->assertJsonPath('errors.tenant.0', 'subscription_suspended')
            ->assertJsonPath('errors.state.0', 'suspended');

        // The session and the billing page still answer, so the screen can say what happened.
        $this->actingAs($this->a['admin'])->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.subscription.state', 'suspended');
        $this->actingAs($this->a['admin'])->getJson('/api/v1/billing')->assertOk();

        // B is untouched, and A's data is all still there.
        $this->actingAs($this->b['admin'])->getJson('/api/v1/leads')->assertOk();
        $this->assertSame(1, $this->asTenant($this->a['tenant'], fn () => Project::count()));

        $this->actingAs($this->owner)->postJson("/api/v1/super-admin/tenants/{$this->a['tenant']->uuid}/subscription/reactivate")->assertOk();
        $this->actingAs($this->a['admin'])->getJson('/api/v1/leads')->assertOk();
    }

    public function test_cancelled_and_expired_close_the_workspace_past_due_does_not(): void
    {
        $tenant = $this->a['tenant'];

        $tenant->forceFill(['subscription_status' => 'past_due', 'subscription_ends_at' => now()->subDay()])->save();
        $this->actingAs($this->a['admin'])->getJson('/api/v1/leads')->assertOk();

        $tenant->forceFill(['subscription_ends_at' => now()->subDays(config('billing.past_due_grace_days') + 1)])->save();
        $this->actingAs($this->a['admin'])->getJson('/api/v1/leads')->assertStatus(402)->assertJsonPath('errors.state.0', 'expired');

        $tenant->forceFill(['subscription_status' => 'trialing', 'trial_ends_at' => now()->subDay()])->save();
        $this->actingAs($this->a['admin'])->getJson('/api/v1/leads')->assertStatus(402)->assertJsonPath('errors.state.0', 'trial_expired');

        $tenant->forceFill(['subscription_status' => 'cancelled'])->save();
        $this->actingAs($this->a['admin'])->getJson('/api/v1/leads')->assertStatus(402)->assertJsonPath('errors.tenant.0', 'subscription_cancelled');
    }

    public function test_the_platform_owner_runs_the_lifecycle_and_each_move_is_on_the_platform_trail(): void
    {
        $uuid = $this->a['tenant']->uuid;
        $growth = Plan::where('key', 'growth')->first();

        $this->actingAs($this->owner)->postJson("/api/v1/super-admin/tenants/{$uuid}/subscription/activate", ['plan_id' => $growth->uuid, 'billing_cycle' => 'monthly'])
            ->assertOk()->assertJsonPath('data.subscription.status', 'active')->assertJsonPath('data.subscription.plan.key', 'growth');

        $this->actingAs($this->owner)->postJson("/api/v1/super-admin/tenants/{$uuid}/subscription/extend", ['days' => 30])->assertOk();
        $this->actingAs($this->owner)->postJson("/api/v1/super-admin/tenants/{$uuid}/subscription/past_due")->assertOk()->assertJsonPath('data.subscription.status', 'past_due');
        $this->actingAs($this->owner)->postJson("/api/v1/super-admin/tenants/{$uuid}/subscription/cancel", ['reason' => 'Closed'])->assertOk()->assertJsonPath('data.subscription.status', 'cancelled');
        $this->actingAs($this->owner)->patchJson("/api/v1/super-admin/tenants/{$uuid}/subscription", ['extra_seats' => 4, 'addons' => ['api'], 'limit_overrides' => ['projects' => 50]])
            ->assertOk()->assertJsonPath('data.subscription.extra_seats', 4)->assertJsonPath('data.subscription.limits.projects', 50);

        $actions = PlatformAuditLog::where('tenant_id', $this->a['tenant']->id)->pluck('action')->all();
        foreach (['subscription.activate', 'subscription.extend', 'subscription.past_due', 'subscription.cancel', 'subscription.updated'] as $action) {
            $this->assertContains($action, $actions);
        }

        // The platform's moves are not written into the company's own trail.
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->where('action', 'like', 'subscription.%')->count());
    }

    public function test_plans_are_managed_from_the_console_not_code(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/super-admin/plans', [
            'key' => 'agency', 'name' => 'Agency', 'included_seats' => 8, 'price_monthly' => 990,
            'features' => ['tracking_hub', 'exports'], 'limits' => ['projects' => 10],
        ])->assertCreated()->assertJsonPath('data.plan.is_priced', true)->assertJsonPath('data.plan.limits.projects', 10);

        $starter = Plan::where('key', 'starter')->first();
        $this->actingAs($this->owner)->patchJson("/api/v1/super-admin/plans/{$starter->uuid}", ['price_monthly' => 199, 'features' => ['exports', 'nonsense']])
            ->assertUnprocessable();
        $this->actingAs($this->owner)->patchJson("/api/v1/super-admin/plans/{$starter->uuid}", ['price_monthly' => 199])
            ->assertOk()->assertJsonPath('data.plan.price_monthly', '199.00');

        $this->assertTrue(PlatformAuditLog::where('action', 'plan.updated')->exists());
    }

    public function test_the_console_is_invisible_to_every_company_user(): void
    {
        foreach (['/api/v1/super-admin/plans', '/api/v1/super-admin/dashboard', '/api/v1/super-admin/audit', "/api/v1/super-admin/tenants/{$this->a['tenant']->uuid}/subscription"] as $path) {
            $this->actingAs($this->a['admin'])->getJson($path)->assertNotFound();
        }

        $this->actingAs($this->a['admin'])->postJson("/api/v1/super-admin/tenants/{$this->a['tenant']->uuid}/subscription/activate", ['plan_id' => Plan::first()->uuid])->assertNotFound();
        $this->actingAs($this->a['admin'])->postJson('/api/v1/super-admin/plans', ['key' => 'x', 'name' => 'x', 'included_seats' => 1])->assertNotFound();
    }

    public function test_revenue_is_computed_from_configured_prices_or_reported_as_not_configured(): void
    {
        $this->actingAs($this->owner)->postJson("/api/v1/super-admin/tenants/{$this->a['tenant']->uuid}/subscription/activate", ['plan_id' => Plan::where('key', 'growth')->value('uuid')])->assertOk();

        // No price configured: N/A, never a fabricated zero.
        $this->actingAs($this->owner)->getJson('/api/v1/super-admin/dashboard')->assertOk()
            ->assertJsonPath('data.revenue.mrr', null)
            ->assertJsonPath('data.revenue.paying_unpriced', 1)
            ->assertJsonPath('data.revenue.invoices_paid_30_days', null);

        Plan::where('key', 'growth')->update(['price_monthly' => 500, 'extra_seat_price' => 50]);
        $this->a['tenant']->forceFill(['extra_seats' => 2])->save();

        $this->actingAs($this->owner)->postJson("/api/v1/super-admin/tenants/{$this->a['tenant']->uuid}/invoices", ['amount' => 600, 'currency' => 'SAR', 'status' => 'paid'])->assertCreated();

        $this->actingAs($this->owner)->getJson('/api/v1/super-admin/dashboard')->assertOk()
            ->assertJsonPath('data.revenue.mrr.SAR', 600)
            ->assertJsonPath('data.revenue.invoices_paid_30_days.SAR', 600)
            ->assertJsonPath('data.companies.total', 2);
    }

    public function test_a_company_reads_only_its_own_invoices_and_only_its_admin_does(): void
    {
        $this->actingAs($this->owner)->postJson("/api/v1/super-admin/tenants/{$this->a['tenant']->uuid}/invoices", ['amount' => 100, 'currency' => 'SAR'])->assertCreated();
        $this->actingAs($this->owner)->postJson("/api/v1/super-admin/tenants/{$this->b['tenant']->uuid}/invoices", ['amount' => 900, 'currency' => 'SAR'])->assertCreated();

        $this->actingAs($this->a['admin'])->getJson('/api/v1/billing')->assertOk()
            ->assertJsonCount(1, 'data.invoices')->assertJsonPath('data.invoices.0.amount', '100.00');
        $this->actingAs($this->b['admin'])->getJson('/api/v1/billing')->assertOk()
            ->assertJsonCount(1, 'data.invoices')->assertJsonPath('data.invoices.0.amount', '900.00');

        $rep = $this->makeUser($this->a['tenant'], Role::Sales);
        $this->actingAs($rep)->getJson('/api/v1/billing')->assertForbidden();

        $this->assertSame(2, Invoice::withoutGlobalScopes()->count());
    }

    public function test_leads_over_the_monthly_allowance_are_still_captured_and_raise_an_alert(): void
    {
        $tenant = $this->putOnPlan($this->a['tenant'], 'starter', ['limit_overrides' => ['leads_per_month' => 1]]);

        $this->actingAs($this->a['admin'])->postJson('/api/v1/leads', ['full_name' => 'One', 'phone' => '0501111111'])->assertCreated();
        $this->actingAs($this->a['admin'])->postJson('/api/v1/leads', ['full_name' => 'Two', 'phone' => '0502222222'])->assertCreated();

        $usage = $this->actingAs($this->a['admin'])->getJson('/api/v1/billing')->assertOk()->json('data.subscription.usage.leads_per_month');
        $this->assertSame(2, $usage['used']);
        $this->assertTrue($usage['over']);
    }
}
