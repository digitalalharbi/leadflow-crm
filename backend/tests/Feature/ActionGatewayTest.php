<?php

namespace Tests\Feature;

use App\Models\ActionLogEntry;
use App\Models\ActionProposal;
use App\Models\AdAccount;
use App\Models\Campaign;
use App\Models\ClientRule;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

/**
 * Agent suggests → proposal → a person decides → it runs once → it is logged.
 * And the limits hold in code, whatever any instruction said.
 */
class ActionGatewayTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Gateway Co');

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

    private function propose(array $data): array
    {
        return $this->actingAs($this->t['admin'])->postJson('/api/v1/action-proposals', $data + [
            'campaign_id' => $this->campaign->uuid, 'reason' => 'تكلفة المؤهل ضعف الهدف',
        ])->assertCreated()->json('data.proposal');
    }

    public function test_nothing_is_sent_until_a_person_approves_and_then_exactly_once(): void
    {
        $proposal = $this->propose(['action' => 'pause']);

        $this->assertSame('pending', $proposal['status']);
        Http::assertNothingSent();

        $this->actingAs($this->t['admin'])->postJson("/api/v1/action-proposals/{$proposal['id']}/approve")->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/c1') && $request['status'] === 'PAUSED');

        $this->asTenant($this->t['tenant'], function () {
            $this->assertSame('executed', ActionProposal::first()->status);
            $this->assertSame('paused', $this->campaign->fresh()->status);
            $this->assertSame(1, ActionLogEntry::count());
            $this->assertTrue(ActionLogEntry::first()->ok);
        });

        // A second approval of the same proposal does nothing.
        $this->actingAs($this->t['admin'])->postJson("/api/v1/action-proposals/{$proposal['id']}/approve")->assertStatus(409);
        Http::assertSentCount(1);
    }

    public function test_the_same_advice_on_the_same_day_is_one_proposal(): void
    {
        $a = $this->propose(['action' => 'pause']);
        $b = $this->propose(['action' => 'pause']);

        $this->assertSame($a['id'], $b['id']);
    }

    public function test_a_budget_cannot_move_further_than_the_client_allows(): void
    {
        $this->asTenant($this->t['tenant'], fn () => ClientRule::create(['max_budget_change_pct' => 20, 'daily_budget_ceiling_minor' => 50000]));

        $tooBig = $this->propose(['action' => 'update_budget', 'payload' => ['daily_budget_minor' => 15000]]);
        $this->assertSame('blocked', $tooBig['status']);
        $this->assertContains('change_too_large', $tooBig['policy']['blocks']);
        $this->assertSame(12000, $tooBig['policy']['limits']['max_minor']);

        $ok = $this->propose(['action' => 'update_budget', 'payload' => ['daily_budget_minor' => 11500]]);
        $this->assertSame('pending', $ok['status']);

        $this->actingAs($this->t['admin'])->postJson("/api/v1/action-proposals/{$ok['id']}/approve")->assertOk();
        Http::assertSent(fn ($request) => (int) $request['daily_budget'] === 11500);
        $this->assertSame(11500, $this->asTenant($this->t['tenant'], fn () => $this->campaign->fresh()->daily_budget_minor));
    }

    public function test_a_blocked_proposal_can_be_edited_into_an_allowed_one(): void
    {
        $blocked = $this->propose(['action' => 'update_budget', 'payload' => ['daily_budget_minor' => 30000]]);
        $this->assertSame('blocked', $blocked['status']);

        $edited = $this->actingAs($this->t['admin'])->patchJson("/api/v1/action-proposals/{$blocked['id']}", ['payload' => ['daily_budget_minor' => 11000]])
            ->assertOk()->json('data.proposal');

        $this->assertSame('pending', $edited['status']);
    }

    public function test_nothing_changes_on_a_blackout_day(): void
    {
        $this->asTenant($this->t['tenant'], fn () => ClientRule::create(['max_budget_change_pct' => 20, 'blackout_days' => [now(config('app.timezone'))->toDateString()]]));

        $proposal = $this->propose(['action' => 'pause']);

        $this->assertSame('blocked', $proposal['status']);
        $this->assertContains('blackout_day', $proposal['policy']['blocks']);
    }

    public function test_forbidden_words_never_reach_an_ad(): void
    {
        $this->asTenant($this->t['tenant'], fn () => ClientRule::create(['max_budget_change_pct' => 20, 'forbidden_words' => ['مضمون']]));

        $proposal = $this->propose(['action' => 'update_creative', 'payload' => ['headline' => 'ربح مضمون 100%']]);

        $this->assertSame('blocked', $proposal['status']);
        $this->assertContains('forbidden_words', $proposal['policy']['blocks']);
    }

    public function test_a_new_campaign_always_starts_paused_and_unbuilt_platforms_refuse(): void
    {
        $created = $this->actingAs($this->t['admin'])->postJson('/api/v1/action-proposals', [
            'action' => 'create_campaign', 'platform' => 'tiktok', 'payload' => ['name' => 'جديدة', 'status' => 'active'], 'reason' => 'اختبار',
        ])->assertCreated()->json('data.proposal');

        $this->assertSame('paused', $created['payload']['status']);
        $this->assertSame('blocked', $created['status']);
        $this->assertContains('platform_not_built', $created['policy']['blocks']);
    }

    public function test_a_campaign_known_only_from_its_leads_cannot_be_paused(): void
    {
        $attributed = $this->asTenant($this->t['tenant'], fn () => Campaign::create(['platform' => 'meta', 'name' => 'من العملاء']));

        $proposal = $this->actingAs($this->t['admin'])->postJson('/api/v1/action-proposals', [
            'action' => 'pause', 'campaign_id' => $attributed->uuid, 'reason' => 'اختبار',
        ])->json('data.proposal');

        $this->assertContains('no_platform_id', $proposal['policy']['blocks']);
    }

    public function test_a_refused_change_is_failed_and_logged_with_the_platform_answer(): void
    {
        Http::swap(new Factory);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Permissions error']], 403)]);

        $proposal = $this->propose(['action' => 'pause']);
        $this->actingAs($this->t['admin'])->postJson("/api/v1/action-proposals/{$proposal['id']}/approve")->assertOk();

        $this->asTenant($this->t['tenant'], function () {
            $p = ActionProposal::first();
            $this->assertSame('failed', $p->status);
            $this->assertStringContainsString('Permissions error', $p->error);
            $this->assertFalse(ActionLogEntry::first()->ok);
            $this->assertSame('active', $this->campaign->fresh()->status);
        });
    }

    public function test_the_log_cannot_be_rewritten(): void
    {
        $proposal = $this->propose(['action' => 'pause']);
        $this->actingAs($this->t['admin'])->postJson("/api/v1/action-proposals/{$proposal['id']}/approve");

        $this->expectException(LogicException::class);
        $this->asTenant($this->t['tenant'], fn () => ActionLogEntry::first()->update(['ok' => false]));
    }

    public function test_reps_cannot_propose_or_decide_and_other_workspaces_cannot_see(): void
    {
        $proposal = $this->propose(['action' => 'pause']);
        $rep = $this->makeUser($this->t['tenant']);

        $this->actingAs($rep)->postJson("/api/v1/action-proposals/{$proposal['id']}/approve")->assertForbidden();
        $this->actingAs($rep)->getJson('/api/v1/action-proposals')->assertForbidden();

        $other = $this->provisionTenant('Other', ['admin_email' => 'o@example.test']);
        $this->actingAs($other['admin'])->postJson("/api/v1/action-proposals/{$proposal['id']}/approve")->assertNotFound();
        $this->assertCount(0, $this->actingAs($other['admin'])->getJson('/api/v1/action-proposals')->json('data.proposals'));
    }

    public function test_client_rules_are_per_project_with_workspace_defaults(): void
    {
        $this->actingAs($this->t['admin'])->putJson('/api/v1/client-rules', ['max_budget_change_pct' => 15, 'target_cpl_minor' => 5000])->assertOk();

        $this->actingAs($this->t['admin'])->getJson('/api/v1/client-rules')
            ->assertJsonPath('data.rules.max_budget_change_pct', 15)
            ->assertJsonPath('data.inherited', false);

        $this->actingAs($this->t['admin'])->putJson('/api/v1/client-rules', ['max_budget_change_pct' => 15, 'blackout_days' => ['someday']])->assertUnprocessable();
    }
}
