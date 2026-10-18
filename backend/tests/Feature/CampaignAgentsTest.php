<?php

namespace Tests\Feature;

use App\Domain\Agents\Analyst;
use App\Domain\Agents\Orchestrator;
use App\Models\ActionProposal;
use App\Models\AgentThread;
use App\Models\Campaign;
use App\Models\ClientRule;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\MetricSnapshot;
use App\Models\Routine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The agents read, judge against the client's rules, and ask. They never act:
 * every change they want arrives in the approval inbox.
 */
class CampaignAgentsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Agents Co');
        Http::fake();
        $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => 'meta', 'name' => 'Meta', 'status' => 'connected', 'credentials' => ['access_token' => 'tok'], 'is_active' => true,
        ]));
    }

    /** A synced campaign with a week of spend and the given leads. */
    private function campaign(int $dailySpendMinor, array $leadStatuses, array $attributes = []): Campaign
    {
        return $this->asTenant($this->t['tenant'], function () use ($dailySpendMinor, $leadStatuses, $attributes) {
            $campaign = Campaign::create(array_merge([
                'platform' => 'meta', 'external_id' => 'c'.(++$this->n), 'name' => 'حملة '.$this->n, 'origin' => 'sync',
                'status' => 'active', 'daily_budget_minor' => 10000,
            ], $attributes));

            foreach (range(0, 6) as $d) {
                MetricSnapshot::create([
                    'campaign_id' => $campaign->id, 'platform' => 'meta', 'level' => 'campaign',
                    'date' => now()->subDays($d)->toDateString(), 'spend_minor' => $dailySpendMinor, 'impressions' => 1000, 'clicks' => 20,
                ]);
            }

            foreach ($leadStatuses as $i => $status) {
                $lead = Lead::create(['full_name' => 'ع', 'phone_normalized' => '+9665'.str_pad((string) ($this->n * 1000 + $i), 8, '0', STR_PAD_LEFT), 'status' => $status]);
                DB::table('leads')->where('id', $lead->id)->update(['campaign_ref_id' => $campaign->id]);
            }

            return $campaign;
        });
    }

    private function runDaily(): AgentThread
    {
        return $this->asTenant($this->t['tenant'], fn () => app(Orchestrator::class)->run('daily_analysis'));
    }

    public function test_spend_with_no_qualified_lead_becomes_a_pause_proposal_not_a_pause(): void
    {
        $campaign = $this->campaign(10000, array_fill(0, 12, 'new'));

        $thread = $this->runDaily();

        $this->asTenant($this->t['tenant'], function () use ($campaign, $thread) {
            $proposal = ActionProposal::first();
            $this->assertSame('pause', $proposal->action);
            $this->assertSame('pending', $proposal->status);
            $this->assertSame('campaign', $proposal->agent);
            $this->assertSame($campaign->id, $proposal->campaign_id);
            $this->assertSame('active', $campaign->fresh()->status, 'An agent paused a campaign by itself.');
            $this->assertSame(3, $thread->messages()->count());
        });

        Http::assertNothingSent();

        // Run again the same day: the same advice is not filed twice.
        $this->runDaily();
        $this->assertSame(1, $this->asTenant($this->t['tenant'], fn () => ActionProposal::count()));
    }

    public function test_over_target_proposes_a_cut_within_the_clients_allowed_change(): void
    {
        $this->asTenant($this->t['tenant'], fn () => ClientRule::create(['target_cpql_minor' => 1000, 'max_budget_change_pct' => 20]));
        // 700 spend / 2 qualified = 350 per qualified lead, target 10.
        $this->campaign(10000, array_merge(array_fill(0, 2, 'qualified'), array_fill(0, 10, 'new')));

        $this->runDaily();

        $proposal = $this->asTenant($this->t['tenant'], fn () => ActionProposal::first());
        $this->assertSame('update_budget', $proposal->action);
        $this->assertSame(8000, $proposal->payload['daily_budget_minor']);
        $this->assertSame('pending', $proposal->status);
    }

    public function test_a_campaign_beating_its_target_is_scaled_but_never_past_the_ceiling(): void
    {
        $this->asTenant($this->t['tenant'], fn () => ClientRule::create(['target_cpql_minor' => 100000, 'max_budget_change_pct' => 20, 'daily_budget_ceiling_minor' => 11000]));
        $this->campaign(1000, array_merge(array_fill(0, 8, 'qualified'), array_fill(0, 4, 'new')));

        $this->runDaily();

        $proposal = $this->asTenant($this->t['tenant'], fn () => ActionProposal::first());
        $this->assertSame('update_budget', $proposal->action);
        $this->assertSame(11000, $proposal->payload['daily_budget_minor']);
    }

    public function test_a_campaign_known_only_from_leads_is_reported_but_not_proposed_for(): void
    {
        $this->campaign(10000, array_fill(0, 12, 'new'), ['origin' => 'lead_attribution', 'external_id' => null]);

        $thread = $this->runDaily();

        $this->asTenant($this->t['tenant'], function () use ($thread) {
            $this->assertSame(0, ActionProposal::count());
            $findings = $thread->messages()->where('agent', 'analyst')->first()->data['findings'];
            $this->assertSame('no_results', $findings[0]['key']);
        });
    }

    public function test_nothing_is_judged_before_it_has_spent_enough(): void
    {
        $this->asTenant($this->t['tenant'], fn () => ClientRule::create(['min_spend_to_judge_minor' => 1000000, 'max_budget_change_pct' => 20]));
        $this->campaign(10000, array_fill(0, 12, 'new'));

        $findings = $this->asTenant($this->t['tenant'], fn () => app(Analyst::class)->analyze());

        $this->assertSame([], array_values(array_filter($findings, fn ($f) => $f->key === 'no_results')));
    }

    public function test_the_budget_guard_alerts_managers_about_a_spend_spike(): void
    {
        $campaign = $this->campaign(1000, []);
        $this->asTenant($this->t['tenant'], fn () => MetricSnapshot::query()
            ->where('campaign_id', $campaign->id)->where('date', now()->subDay()->toDateString())->update(['spend_minor' => 9000]));

        $this->asTenant($this->t['tenant'], fn () => app(Orchestrator::class)->run('budget_guard'));

        $notification = DB::table('notifications')->where('notifiable_id', $this->t['admin']->id)->first();
        $this->assertNotNull($notification, 'No manager was told about the spike.');
        $this->assertStringContainsString('قفزة في الصرف', $notification->data);
    }

    public function test_routines_run_when_due_in_the_workspaces_timezone_and_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 08:50', 'Asia/Riyadh'));

        $this->artisan('leadflow:run-routines', ['--tenant' => $this->t['tenant']->slug])->assertSuccessful();
        $ran = $this->asTenant($this->t['tenant'], fn () => Routine::query()->where('key', 'daily_analysis')->first()->last_run_at);
        $this->assertNotNull($ran);

        $this->artisan('leadflow:run-routines', ['--tenant' => $this->t['tenant']->slug]);
        $this->assertSame(1, $this->asTenant($this->t['tenant'], fn () => AgentThread::query()->where('routine', 'daily_analysis')->count()));

        Carbon::setTestNow();
    }

    public function test_agents_screen_is_for_managers_and_each_workspace_sees_its_own(): void
    {
        $this->runDaily();
        $rep = $this->makeUser($this->t['tenant']);

        $this->actingAs($rep)->getJson('/api/v1/agents')->assertForbidden();
        $this->actingAs($rep)->postJson('/api/v1/agents/routines/daily_analysis/run')->assertForbidden();

        $data = $this->actingAs($this->t['admin'])->getJson('/api/v1/agents')->assertOk()->json('data');
        $this->assertCount(3, $data['agents']);
        $this->assertNotEmpty($data['threads']);

        $other = $this->provisionTenant('Other', ['admin_email' => 'x@example.test']);
        $this->assertCount(0, $this->actingAs($other['admin'])->getJson('/api/v1/agents')->json('data.threads'));
    }
}
