<?php

namespace Tests\Feature;

use App\Domain\Actions\ActionGateway;
use App\Enums\Role;
use App\Models\AdAccount;
use App\Models\Campaign;
use App\Models\ClientRule;
use App\Models\Integration;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Safe self-approve: a person's own pause, resume or guarded budget change runs at once when the
 * workspace says so — off by default, a project's own say, a guard on the budget, everything else
 * and every agent still waiting for a person, and all of it on the audit trail.
 */
class SelfApprovalTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Self Co');

        $this->campaign = $this->asTenant($this->t['tenant'], function () {
            Integration::create(['provider' => 'meta', 'name' => 'Meta', 'status' => 'connected', 'credentials' => ['access_token' => 'tok'], 'is_active' => true]);
            $account = AdAccount::create(['platform' => 'meta', 'external_id' => '999', 'currency' => 'SAR']);

            return Campaign::create([
                'platform' => 'meta', 'external_id' => 'c1', 'name' => 'حملة', 'origin' => 'sync',
                'status' => 'active', 'daily_budget_minor' => 10000, 'ad_account_id' => $account->id,
            ]);
        });

        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
    }

    private function settings(array $data, ?Project $project = null, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->t['admin'])->patchJson('/api/v1/settings/self-approval', $data + ($project ? ['project_id' => $project->uuid] : []));
    }

    private function operate(string $action, array $extra = [], ?User $as = null, ?Campaign $campaign = null): array
    {
        return $this->actingAs($as ?? $this->t['admin'])->postJson('/api/v1/campaigns/'.($campaign ?? $this->campaign)->uuid.'/operate', $extra + ['action' => $action, 'reason' => 'التكلفة أعلى من المتوسط'])
            ->assertCreated()->json('data.proposal');
    }

    // ------------------------------------------------------------------ the default

    public function test_off_by_default_every_change_waits_in_the_inbox(): void
    {
        $state = $this->actingAs($this->t['admin'])->getJson('/api/v1/settings/self-approval')->assertOk()->json('data');
        $this->assertFalse($state['enabled']);
        $this->assertSame([], $state['actions']);
        $this->assertSame(0, $state['max_budget_change_pct']);
        $this->assertSame(['pause', 'resume', 'update_budget'], $state['configurable']);
        $this->assertEqualsCanonicalizing(['duplicate_ad_set', 'create_campaign', 'create_ad', 'update_creative', 'update_targeting', 'schedule'], $state['always_manual']);

        $proposal = $this->operate('pause');
        $this->assertSame('pending', $proposal['status']);
        $this->assertSame('manual', $proposal['approval']);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ on

    public function test_a_pause_switched_on_runs_at_once_and_the_trail_says_the_mode_did_it(): void
    {
        $this->settings(['enabled' => true, 'actions' => ['pause']])->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.actions', ['pause']);

        $proposal = $this->operate('pause');

        $this->assertSame('executed', $proposal['status']);
        $this->assertSame('self', $proposal['approval']);
        $this->assertSame($this->t['admin']->name, $proposal['decider']);
        Http::assertSentCount(1);
        $this->assertSame('paused', $this->asTenant($this->t['tenant'], fn () => $this->campaign->fresh()->status));

        $this->assertDatabaseHas('audit_logs', ['action' => 'action_proposal.self_approved']);
        $trail = $this->actingAs($this->t['admin'])->getJson('/api/v1/audit?category=campaigns')->assertOk()->json('data.entries');
        $this->assertNotEmpty(array_filter($trail, fn ($e) => $e['action'] === 'action_proposal.self_approved'));

        // The resume half is not on: putting it back waits for a person.
        $back = $this->operate('resume');
        $this->assertSame('pending', $back['status']);
        $this->assertSame('manual', $back['approval']);
        Http::assertSentCount(1);
    }

    public function test_a_budget_change_runs_at_once_only_within_the_guard(): void
    {
        $this->settings(['enabled' => true, 'actions' => ['update_budget']])->assertOk();

        // No guard set: a budget never moves by itself.
        $this->assertSame('pending', $this->operate('set_budget', ['daily_budget' => 105])['status']);

        $this->settings(['max_budget_change_pct' => 10])->assertOk()->assertJsonPath('data.max_budget_change_pct', 10);

        $small = $this->operate('set_budget', ['daily_budget' => 108]);
        $this->assertSame('executed', $small['status']);
        $this->assertSame('self', $small['approval']);
        $this->assertSame(10800, $this->asTenant($this->t['tenant'], fn () => $this->campaign->fresh()->daily_budget_minor));

        // Fifteen percent is past the guard (and inside the client's own 20%): a person decides.
        $big = $this->operate('set_budget', ['daily_budget' => 124]);
        $this->assertSame('pending', $big['status']);
        $this->assertSame('manual', $big['approval']);

        // The guard cannot be used to get round the client's rules: 30% is blocked whatever the mode says.
        $this->settings(['max_budget_change_pct' => 100])->assertOk()->assertJsonPath('data.max_budget_change_pct', 100);
        $this->asTenant($this->t['tenant'], fn () => ClientRule::create(['max_budget_change_pct' => 20]));
        $this->assertSame('blocked', $this->operate('set_budget', ['daily_budget' => 150])['status']);
    }

    public function test_high_risk_changes_and_an_agents_proposals_always_wait(): void
    {
        $this->settings(['enabled' => true, 'actions' => ['pause', 'resume', 'update_budget'], 'max_budget_change_pct' => 100])->assertOk();

        $creative = $this->actingAs($this->t['admin'])->postJson('/api/v1/action-proposals', [
            'action' => 'update_creative', 'campaign_id' => $this->campaign->uuid, 'payload' => ['headline' => 'عرض'], 'reason' => 'تجربة',
        ])->assertCreated()->json('data.proposal');
        $this->assertSame('pending', $creative['status']);

        $agent = $this->asTenant($this->t['tenant'], fn () => app(ActionGateway::class)->propose([
            'action' => 'pause', 'platform' => 'meta', 'campaign_id' => $this->campaign->id, 'reason' => 'ينصح الوكيل بالإيقاف',
        ], null, 'budget_agent'));
        $this->assertSame('pending', $agent->status);
        $this->assertSame('manual', $agent->approval);
        Http::assertNothingSent();
    }

    public function test_the_mode_saves_a_click_and_never_gives_a_power(): void
    {
        $this->settings(['enabled' => true, 'actions' => ['pause']])->assertOk();

        // A role that may run campaign operations but may not approve a proposal.
        $roleId = $this->actingAs($this->t['admin'])->postJson('/api/v1/access/roles', [
            'name' => 'Ops Only', 'base_role' => 'marketing_manager',
            'grants' => ['dashboard' => ['view'], 'campaigns' => ['view', 'create', 'edit', 'execute'], 'leads' => ['view', 'export'], 'analytics' => ['view']],
        ])->assertCreated()->json('data.role.id');
        $ops = $this->makeUser($this->t['tenant'], Role::MarketingManager);
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/users/{$ops->uuid}", ['access_role_id' => $roleId])->assertOk();

        $proposal = $this->operate('pause', [], $ops->fresh());
        $this->assertSame('pending', $proposal['status']);
        $this->assertSame('manual', $proposal['approval']);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ a project's own say

    public function test_a_project_can_say_otherwise_either_way(): void
    {
        [$quiet, $brave] = $this->asTenant($this->t['tenant'], fn () => [Project::create(['name' => 'هادئ']), Project::create(['name' => 'جريء'])]);
        [$inQuiet, $inBrave] = $this->asTenant($this->t['tenant'], fn () => [
            Campaign::create(['platform' => 'meta', 'external_id' => 'q1', 'name' => 'حملة هادئ', 'origin' => 'sync', 'status' => 'active', 'daily_budget_minor' => 10000, 'project_id' => $quiet->id, 'ad_account_id' => $this->campaign->ad_account_id]),
            Campaign::create(['platform' => 'meta', 'external_id' => 'b1', 'name' => 'حملة جريء', 'origin' => 'sync', 'status' => 'active', 'daily_budget_minor' => 10000, 'project_id' => $brave->id, 'ad_account_id' => $this->campaign->ad_account_id]),
        ]);

        // Workspace: on for pause. The quiet project switches it off for itself.
        $this->settings(['enabled' => true, 'actions' => ['pause']])->assertOk();
        $this->settings(['enabled' => false], $quiet)->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.enabled_source', 'project')->assertJsonPath('data.project.name', 'هادئ');

        $this->assertSame('pending', $this->operate('pause', [], null, $inQuiet)['status']);
        $this->assertSame('executed', $this->operate('pause', [], null, $inBrave)['status']);

        // Workspace off; the brave project on for itself.
        $this->settings(['enabled' => false])->assertOk();
        $this->settings(['enabled' => true, 'actions' => ['resume']], $brave)->assertOk();
        $this->asTenant($this->t['tenant'], fn () => $inQuiet->fresh()->update(['status' => 'paused']));

        $this->assertSame('executed', $this->operate('resume', [], null, $inBrave)['status']);
        $this->assertSame('pending', $this->operate('resume', [], null, $inQuiet)['status']);

        // The workspace's own screen still shows its own setting, and the project's shows where each part came from.
        $this->actingAs($this->t['admin'])->getJson('/api/v1/settings/self-approval')->assertJsonPath('data.enabled', false);
        $this->actingAs($this->t['admin'])->getJson("/api/v1/settings/self-approval?project_id={$brave->uuid}")->assertJsonPath('data.enabled', true)->assertJsonPath('data.actions_source', 'project');
    }

    // ------------------------------------------------------------------ the policy still runs

    public function test_the_policy_is_checked_as_for_any_approval_and_a_blocked_self_approval_is_on_the_record(): void
    {
        $this->settings(['enabled' => true, 'actions' => ['pause']])->assertOk();
        $this->asTenant($this->t['tenant'], fn () => ClientRule::create(['max_budget_change_pct' => 20, 'blackout_days' => [now(config('app.timezone'))->toDateString()]]));

        $blackout = $this->operate('pause');
        $this->assertSame('blocked', $blackout['status']);
        $this->assertContains('blackout_day', $blackout['policy']['blocks']);
        Http::assertNothingSent();

        // No account to send to: the mode said yes, the policy (checked for running) said no — and the trail has both.
        $this->asTenant($this->t['tenant'], fn () => ClientRule::query()->delete());
        $this->asTenant($this->t['tenant'], fn () => Integration::query()->delete());
        $other = $this->asTenant($this->t['tenant'], fn () => Campaign::create(['platform' => 'meta', 'external_id' => 'c2', 'name' => 'بلا حساب', 'origin' => 'sync', 'status' => 'active', 'daily_budget_minor' => 10000]));

        $proposal = $this->operate('pause', [], null, $other);
        $this->assertSame('blocked', $proposal['status']);
        $this->assertSame('self', $proposal['approval']);
        $this->assertContains('not_connected', $proposal['policy']['blocks']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'action_proposal.self_approved']);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ who, and the screen

    public function test_only_an_admin_changes_it_each_change_is_audited_and_workspaces_never_share(): void
    {
        $manager = $this->makeUser($this->t['tenant'], Role::Manager);
        $this->settings(['enabled' => true], null, $manager)->assertForbidden();
        $this->actingAs($manager)->getJson('/api/v1/settings/self-approval')->assertOk();
        $this->actingAs($this->makeUser($this->t['tenant'], Role::Finance))->patchJson('/api/v1/settings/self-approval', ['enabled' => true])->assertForbidden();

        $this->settings(['enabled' => true, 'actions' => ['pause', 'nonsense'], 'max_budget_change_pct' => 250])->assertStatus(422);
        $this->settings(['enabled' => true, 'actions' => ['pause'], 'max_budget_change_pct' => 15])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'self_approval.changed']);

        $other = $this->provisionTenant('Else');
        $this->actingAs($other['admin'])->getJson('/api/v1/settings/self-approval')->assertOk()->assertJsonPath('data.enabled', false);

        // Cleared: back to inheriting, nothing stored.
        $this->settings(['enabled' => null, 'actions' => null, 'max_budget_change_pct' => null])->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.enabled_source', 'default');
    }

    public function test_the_operations_table_knows_which_of_its_actions_run_at_once(): void
    {
        $row = fn (User $as) => collect($this->actingAs($as)->getJson('/api/v1/campaigns/operations')->assertOk()->json('data.campaigns'))->firstWhere('id', $this->campaign->uuid);

        $this->assertSame([], $row($this->t['admin'])['instant']);

        $this->settings(['enabled' => true, 'actions' => ['pause', 'resume', 'update_budget'], 'max_budget_change_pct' => 10])->assertOk();
        $this->assertSame(['pause', 'resume', 'set_status', 'set_budget'], $row($this->t['admin'])['instant']);
        $this->assertTrue($this->actingAs($this->t['admin'])->getJson('/api/v1/campaigns/operations')->json('data.self_approval.enabled'));

        // Without the guard a budget change is not instant, whatever is ticked.
        $this->settings(['max_budget_change_pct' => 0])->assertOk();
        $this->assertSame(['pause', 'resume', 'set_status'], $row($this->t['admin'])['instant']);
    }
}
