<?php

namespace Tests\Feature;

use App\Domain\Access\AccessResolver;
use App\Domain\Access\Permissions;
use App\Enums\Role;
use App\Models\AccessRole;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\PermissionRule;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Access a workspace shapes itself: roles it builds, rules in four layers (workspace → role →
 * project → user), switches for single pieces of the interface — and that the API honours them.
 */
class DynamicAccessTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Access Co');
    }

    private function build(array $overrides = []): string
    {
        return $this->actingAs($this->t['admin'])->postJson('/api/v1/access/roles', $overrides + [
            'name' => 'Sales Rep Lite', 'base_role' => 'sales_manager',
            'grants' => ['dashboard' => ['view'], 'leads' => ['view', 'edit', 'execute'], 'visits' => ['view', 'create'], 'reservations' => ['view'], 'contacts' => ['view']],
        ])->assertCreated()->json('data.role.id');
    }

    private function holder(string $roleId): User
    {
        return tap($this->makeUser($this->t['tenant'], Role::Sales), function (User $u) use ($roleId) {
            $this->actingAs($this->t['admin'])->patchJson("/api/v1/users/{$u->uuid}", ['access_role_id' => $roleId])->assertOk();
        })->fresh();
    }

    private function rule(string $layer, string $permission, string $effect, array $scope = []): void
    {
        $this->asTenant($this->t['tenant'], fn () => PermissionRule::create($scope + ['layer' => $layer, 'permission' => $permission, 'effect' => $effect]));
    }

    private function me(User $user): array
    {
        return $this->actingAs($user)->getJson('/api/v1/auth/me')->assertOk()->json('data');
    }

    public function test_a_built_role_holds_exactly_what_it_was_given_and_the_api_refuses_the_rest(): void
    {
        $user = $this->holder($this->build());

        $this->assertSame(Role::SalesManager, $user->role);
        $this->assertNotNull($user->access_role_id);

        $me = $this->me($user);
        $this->assertContains('leads.edit', $me['permissions']);
        $this->assertNotContains('spend.view', $me['permissions']);
        $this->assertNotContains('campaigns.view', $me['permissions']);
        $this->assertEqualsCanonicalizing(['spend', 'revenue'], $me['hidden_data']);
        $this->assertContains('dashboard.kpi.revenue', $me['hidden_elements']);
        $this->assertNotContains('dashboard.kpi.leads', $me['hidden_elements']);

        $this->actingAs($user)->getJson('/api/v1/leads')->assertOk();
        $this->actingAs($user)->getJson('/api/v1/campaigns')->assertForbidden();
        $this->actingAs($user)->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_the_money_is_scrubbed_from_what_a_built_role_reads(): void
    {
        $lead = $this->asTenant($this->t['tenant'], fn () => Lead::create(['full_name' => 'عميل', 'phone_normalized' => '+966551110000', 'status' => 'won', 'deal_value_minor' => 50_000_000]));
        $user = $this->holder($this->build());

        $as = fn (User $u) => json_encode($this->actingAs($u)->getJson("/api/v1/leads/{$lead->uuid}")->assertOk()->json());

        $this->assertStringContainsString('deal_value', $as($this->t['admin']));
        $this->assertStringNotContainsString('deal_value', $as($user));
    }

    public function test_layers_override_one_another_in_order(): void
    {
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
        $user = $this->holder($this->build());
        $key = AccessRole::first()->key;

        // Workspace default: everybody may see spend. The role does not say — so the default stands.
        $this->rule('workspace', 'spend.view', 'allow');
        $this->assertTrue(Permissions::has($user->fresh(), 'spend.view'));

        // The role says no: it wins over the default.
        $this->rule('role', 'spend.view', 'deny', ['role_key' => $key]);
        $this->assertFalse(Permissions::has($user->fresh(), 'spend.view'));

        // A project says yes for this role — but only in that project.
        $this->rule('project', 'spend.view', 'allow', ['project_id' => $project->id, 'role_key' => $key]);
        $this->assertFalse(Permissions::has($user->fresh(), 'spend.view'));
        $this->assertTrue(Permissions::has($user->fresh(), 'spend.view', $project->id));

        // The person says no: last word, even inside the project.
        $this->rule('user', 'spend.view', 'deny', ['user_id' => $user->id]);
        $this->assertFalse(Permissions::has($user->fresh(), 'spend.view', $project->id));
    }

    public function test_a_built_in_role_can_be_changed_and_the_change_is_the_workspaces_alone(): void
    {
        $finance = $this->makeUser($this->t['tenant'], Role::Finance);
        $this->actingAs($finance)->getJson('/api/v1/campaigns')->assertOk();

        $other = $this->provisionTenant('Other Co');
        $otherFinance = $this->makeUser($other['tenant'], Role::Finance);

        $grants = collect(Permissions::group(Permissions::forRole(Role::Finance)))->except('campaigns')->all();
        $this->actingAs($this->t['admin'])->patchJson('/api/v1/access/roles/finance', ['grants' => $grants])->assertOk();

        $this->actingAs($finance->fresh())->getJson('/api/v1/campaigns')->assertForbidden();
        $this->actingAs($otherFinance)->getJson('/api/v1/campaigns')->assertOk();

        $state = $this->actingAs($this->t['admin'])->getJson('/api/v1/access')->json('data');
        $row = collect($state['roles'])->firstWhere('key', 'finance');
        $this->assertArrayNotHasKey('campaigns', $row['grants']);
        $this->assertArrayHasKey('campaigns', $row['base_grants']);
    }

    public function test_a_long_standing_role_can_be_held_back_but_never_widened(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $this->actingAs($rep)->getJson('/api/v1/appointments')->assertOk();

        $this->rule('role', 'visits.view', 'deny', ['role_key' => 'sales']);
        $this->actingAs($rep->fresh())->getJson('/api/v1/appointments')->assertForbidden();

        // Allowing something its policies never gave it changes what is offered, not what is possible.
        $this->rule('role', 'users.view', 'allow', ['role_key' => 'sales']);
        $this->actingAs($rep->fresh())->postJson('/api/v1/users', ['name' => 'x', 'email' => 'x@example.test', 'password' => 'password123', 'role' => 'admin'])->assertForbidden();
    }

    public function test_an_element_switched_off_is_gone_from_the_session_and_from_the_response(): void
    {
        $campaign = $this->asTenant($this->t['tenant'], fn () => Campaign::create(['name' => 'حملة', 'platform' => 'meta', 'external_id' => 'c1', 'origin' => 'sync', 'status' => 'active', 'daily_budget_minor' => 50000]));
        $manager = $this->makeUser($this->t['tenant'], Role::MarketingManager);

        $before = $this->actingAs($manager)->getJson('/api/v1/campaigns')->assertOk()->json();
        $this->assertStringContainsString('daily_budget', json_encode($before));

        $this->actingAs($this->t['admin'])->patchJson('/api/v1/access/roles/marketing_manager', ['elements' => [['key' => 'campaigns.field.budget', 'state' => 'hide']]])->assertOk();

        $this->assertContains('campaigns.field.budget', $this->me($manager->fresh())['hidden_elements']);
        $after = $this->actingAs($manager->fresh())->getJson('/api/v1/campaigns')->assertOk()->json();
        $this->assertStringNotContainsString('daily_budget', json_encode($after));
        $this->assertNotNull($campaign);
    }

    public function test_an_element_cannot_be_forced_on_for_somebody_who_may_not_see_what_it_shows(): void
    {
        $user = $this->holder($this->build());

        $this->actingAs($this->t['admin'])->patchJson("/api/v1/access/roles/{$this->roleKey()}", ['elements' => [['key' => 'dashboard.kpi.revenue', 'state' => 'show']]])->assertOk();

        $this->assertContains('dashboard.kpi.revenue', $this->me($user->fresh())['hidden_elements']);
    }

    private function roleKey(): string
    {
        return AccessRole::first()->key;
    }

    public function test_only_an_admin_shapes_access_and_an_admin_is_never_narrowed(): void
    {
        $manager = $this->makeUser($this->t['tenant'], Role::Manager);
        $salesManager = $this->makeUser($this->t['tenant'], Role::SalesManager);

        foreach ([$manager, $salesManager] as $who) {
            $this->actingAs($who)->getJson('/api/v1/access')->assertForbidden();
            $this->actingAs($who)->postJson('/api/v1/access/roles', ['name' => 'x', 'base_role' => 'finance'])->assertForbidden();
        }

        $this->actingAs($this->t['admin'])->patchJson('/api/v1/access/roles/admin', ['grants' => []])->assertStatus(422);
        $this->rule('workspace', 'leads.view', 'deny');
        $this->assertTrue(Permissions::has($this->t['admin']->fresh(), 'leads.view'));
        $this->assertSame([], Permissions::hiddenElements($this->t['admin']->fresh()));
    }

    public function test_a_built_role_cannot_be_given_the_power_to_change_who_may_do_what(): void
    {
        $id = $this->build(['grants' => ['users' => ['view', 'create', 'edit'], 'settings' => ['view', 'edit'], 'leads' => ['view']]]);
        $role = AccessRole::where('uuid', $id)->first();

        $this->assertSame(['view'], $role->grants['users']);
        $this->assertSame(['view'], $role->grants['settings']);

        $this->actingAs($this->t['admin'])->putJson('/api/v1/access/rules', ['layer' => 'workspace', 'rules' => [['permission' => 'users.edit', 'effect' => 'allow']]])->assertStatus(422);
        $this->actingAs($this->t['admin'])->putJson('/api/v1/access/rules', ['layer' => 'workspace', 'rules' => [['permission' => 'nonsense.view', 'effect' => 'allow']]])->assertStatus(422);
        $this->actingAs($this->t['admin'])->putJson('/api/v1/access/rules', ['layer' => 'workspace', 'rules' => [['permission' => 'ui:nope', 'effect' => 'deny']]])->assertStatus(422);
    }

    public function test_rules_are_replaced_per_scope_and_audited(): void
    {
        $user = $this->makeUser($this->t['tenant'], Role::MarketingManager);

        $this->actingAs($this->t['admin'])->putJson('/api/v1/access/rules', ['layer' => 'user', 'user_id' => $user->uuid, 'rules' => [['permission' => 'ui:dashboard.kpi.response', 'effect' => 'deny']]])->assertOk();
        $this->assertContains('dashboard.kpi.response', $this->me($user->fresh())['hidden_elements']);

        $this->actingAs($this->t['admin'])->putJson('/api/v1/access/rules', ['layer' => 'user', 'user_id' => $user->uuid, 'rules' => []])->assertOk();
        $this->assertNotContains('dashboard.kpi.response', $this->me($user->fresh())['hidden_elements']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'access.rules_changed']);

        $effective = $this->actingAs($this->t['admin'])->getJson("/api/v1/access/users/{$user->uuid}")->assertOk()->json('data');
        $this->assertSame('marketing_manager', $effective['role']);
    }

    public function test_a_role_in_use_is_not_deleted_and_another_workspaces_role_is_not_assignable(): void
    {
        $id = $this->build();
        $this->holder($id);

        $this->actingAs($this->t['admin'])->deleteJson("/api/v1/access/roles/{$id}")->assertStatus(422);

        $other = $this->provisionTenant('Elsewhere');
        $foreign = $this->actingAs($other['admin'])->postJson('/api/v1/access/roles', ['name' => 'Theirs', 'base_role' => 'finance', 'grants' => []])->json('data.role.id');
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/users/{$rep->uuid}", ['access_role_id' => $foreign])->assertStatus(422);

        $free = $this->build(['name' => 'Spare']);
        $this->actingAs($this->t['admin'])->deleteJson("/api/v1/access/roles/{$free}")->assertOk();
        $this->assertNull(AccessRole::where('uuid', $free)->first());
    }

    public function test_choosing_a_built_in_role_again_clears_the_built_one(): void
    {
        $user = $this->holder($this->build());

        $this->actingAs($this->t['admin'])->patchJson("/api/v1/users/{$user->uuid}", ['role' => 'finance'])->assertOk();

        $user = $user->fresh();
        $this->assertNull($user->access_role_id);
        $this->assertNull(AccessResolver::customRole($user));
        $this->assertSame(Role::Finance, $user->role);
    }

    public function test_a_built_role_that_is_project_scoped_sees_only_its_projects(): void
    {
        [$a, $b] = $this->asTenant($this->t['tenant'], fn () => [Project::create(['name' => 'أ']), Project::create(['name' => 'ب'])]);
        $this->asTenant($this->t['tenant'], function () use ($a, $b) {
            Lead::create(['full_name' => 'في أ', 'phone_normalized' => '+966551110001', 'status' => 'new', 'project_id' => $a->id]);
            Lead::create(['full_name' => 'في ب', 'phone_normalized' => '+966551110002', 'status' => 'new', 'project_id' => $b->id]);
        });

        $id = $this->build(['base_role' => 'project_manager', 'project_scoped' => true]);
        $user = $this->holder($id);
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/users/{$user->uuid}", ['project_ids' => [$a->uuid]])->assertOk();

        $this->assertSame([$a->uuid], $this->me($user->fresh())['project_scope']);
        $names = collect($this->actingAs($user->fresh())->getJson('/api/v1/leads')->json('data'))->pluck('full_name')->all();
        $this->assertSame(['في أ'], $names);
    }
}
