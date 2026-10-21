<?php

namespace Tests\Feature;

use App\Domain\Actions\ActionPolicy;
use App\Domain\Campaigns\Adapters\SandboxAdapter;
use App\Domain\Campaigns\AdPlatformRegistry;
use App\Domain\Campaigns\CampaignQuality;
use App\Enums\Role;
use App\Models\ActionLogEntry;
use App\Models\ActionProposal;
use App\Models\AdConnection;
use App\Models\Campaign;
use App\Models\ConversionExport;
use App\Models\Lead;
use App\Models\MetricSnapshot;
use App\Models\Project;
use App\Models\TrackingLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The operations table: what each campaign costs and brings, how good it is and why, and the five
 * things a person can do to it — each approval-aware, audited and safe to ask twice.
 */
class CampaignOperationsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Ops Co');
        Http::fake();
    }

    /** A campaign with spend yesterday and leads who got as far as they were told to. */
    private function campaign(string $name, int $spendMinor, int $leads, int $qualified = 0, string $platform = 'meta', array $extra = []): Campaign
    {
        return $this->asTenant($this->t['tenant'], function () use ($name, $spendMinor, $leads, $qualified, $platform, $extra) {
            $campaign = Campaign::create($extra + ['platform' => $platform, 'external_id' => 'x'.random_int(1000, 9999), 'name' => $name, 'origin' => 'sync', 'status' => 'active', 'daily_budget_minor' => 10000, 'currency' => 'SAR']);
            MetricSnapshot::create(['platform' => $platform, 'level' => 'campaign', 'campaign_id' => $campaign->id, 'date' => now()->subDay()->toDateString(), 'spend_minor' => $spendMinor, 'currency' => 'SAR']);

            foreach (range(1, $leads) as $i) {
                $lead = Lead::create(['full_name' => "{$name} {$i}", 'phone_normalized' => '+966'.random_int(500000000, 599999999), 'status' => $i <= $qualified ? 'qualified' : 'contacted', 'qualification_status' => $i <= $qualified ? 'qualified' : 'unqualified']);
                DB::table('leads')->where('id', $lead->id)->update(['campaign_ref_id' => $campaign->id, 'created_at' => now()->subDay(), 'utm_source' => 'meta']);
            }

            return $campaign;
        });
    }

    private function operate(Campaign $campaign, array $data, $as = null)
    {
        return $this->actingAs($as ?? $this->t['admin'])->postJson("/api/v1/campaigns/{$campaign->uuid}/operate", $data);
    }

    public function test_every_campaign_has_a_score_a_class_a_reason_and_an_action(): void
    {
        // The account averages SAR 100 a lead; one campaign pays 40, one pays 300 and brings nobody who qualifies.
        $good = $this->campaign('رخيصة وجيدة', 400_000, 10, 6);
        $bad = $this->campaign('غالية وضعيفة', 3_000_000, 10, 0);

        $data = $this->actingAs($this->t['admin'])->getJson('/api/v1/campaigns/operations')->assertOk()->json('data');
        $rows = collect($data['campaigns'])->keyBy('id');

        $this->assertGreaterThan($rows[$bad->uuid]['quality']['score'], $rows[$good->uuid]['quality']['score']);
        $this->assertContains($rows[$good->uuid]['quality']['class'], ['excellent', 'healthy']);
        $this->assertContains($rows[$bad->uuid]['quality']['class'], ['at_risk', 'critical', 'watch']);

        $quality = $rows[$bad->uuid]['quality'];
        $this->assertNotEmpty($quality['why'], 'a weak score says why');
        $this->assertCount(2, $quality['why']);
        $this->assertNotNull($quality['action']);
        $this->assertContains($quality['action']['key'], ['sharpen_targeting', 'refresh_creative', 'follow_up_visits', 'cut_budget', 'tighten_audience', 'add_tracking_links', 'fix_tracking']);
        $this->assertEqualsCanonicalizing(array_keys($data['classes']), array_unique(array_column(array_column($data['campaigns'], 'quality'), 'class')));

        // What the table is for: the row carries everything and what may be done to it.
        foreach (['status', 'daily_budget', 'spend', 'platform', 'project', 'objective', 'leads', 'interested', 'visits', 'reservations', 'won', 'revenue', 'pending_proposals', 'can'] as $key) {
            $this->assertArrayHasKey($key, $rows[$good->uuid]);
        }
        $this->assertContains('set_budget', $rows[$good->uuid]['can']);
        $this->assertContains('sync', $rows[$good->uuid]['can']);
        $this->assertNotContains('delete', $rows[$good->uuid]['can']);
        $this->assertFalse($data['sandbox']);
    }

    public function test_the_score_is_arithmetic_a_person_can_check(): void
    {
        $this->assertSame(70, CampaignQuality::costScore(1.0), 'as much as the account averages is a 70');
        $this->assertSame(100, CampaignQuality::costScore(0.5));
        $this->assertSame(10, CampaignQuality::costScore(2.0));
        $this->assertSame(0, CampaignQuality::costScore(5.0));
        $this->assertSame(50, CampaignQuality::roasScore(1.0), 'break-even is 50');
        $this->assertSame(100, CampaignQuality::roasScore(3.0));
        $this->assertSame(0, CampaignQuality::roasScore(0.0));

        foreach ([[90, 'excellent'], [85, 'excellent'], [84, 'healthy'], [70, 'healthy'], [69, 'watch'], [55, 'watch'], [54, 'at_risk'], [40, 'at_risk'], [39, 'critical'], [0, 'critical']] as [$score, $class]) {
            $this->assertSame($class, CampaignQuality::classify($score), "{$score}");
        }

        // Left out what it has no data for, and weighs the rest again.
        $row = ['leads' => 20, 'qualified' => 10, 'cpl' => 100.0, 'spend' => 2000, 'roas' => null];
        $result = CampaignQuality::score($row, ['cpl' => 100.0], 100, null);
        $this->assertSame(['cpl', 'lead_quality', 'attribution'], array_column($result['components'], 'key'));
        // (70·8 + 100·14 + 100·8) / 30 = 92
        $this->assertSame(92, $result['score']);
        $this->assertSame('low', $result['confidence']);

        $this->assertNull(CampaignQuality::score(['leads' => 0], [], null, null)['score']);
    }

    public function test_pausing_files_a_proposal_that_waits_and_asking_twice_is_the_same_request(): void
    {
        $campaign = $this->campaign('للإيقاف', 100_000, 3);

        $first = $this->operate($campaign, ['action' => 'pause', 'reason' => 'التكلفة مرتفعة'])->assertCreated()->json('data');
        $this->assertSame('proposal', $first['kind']);
        $this->assertSame('pending', $first['proposal']['status']);
        $this->assertSame('pause', $first['proposal']['action']);

        $second = $this->operate($campaign, ['action' => 'pause', 'reason' => 'التكلفة مرتفعة'])->assertCreated()->json('data');
        $this->assertSame($first['proposal']['id'], $second['proposal']['id']);
        $this->asTenant($this->t['tenant'], fn () => $this->assertSame(1, ActionProposal::count()));

        // Nothing has happened to the campaign yet: it waits for a decision.
        $this->assertSame('active', $this->asTenant($this->t['tenant'], fn () => $campaign->fresh()->status));
        $this->assertSame(1, $this->actingAs($this->t['admin'])->getJson('/api/v1/campaigns/operations')->json('data.campaigns.0.pending_proposals'));
    }

    public function test_status_budget_and_the_rest_each_say_what_they_need(): void
    {
        $campaign = $this->campaign('للضبط', 100_000, 3);

        $this->operate($campaign, ['action' => 'pause'])->assertStatus(422);
        $this->operate($campaign, ['action' => 'set_budget', 'reason' => 'x'])->assertStatus(422);
        $this->operate($campaign, ['action' => 'set_budget', 'reason' => 'x', 'daily_budget' => 0])->assertStatus(422);
        $this->operate($campaign, ['action' => 'set_status', 'reason' => 'x', 'status' => 'archived'])->assertStatus(422);

        $budget = $this->operate($campaign, ['action' => 'set_budget', 'reason' => 'نرفع', 'daily_budget' => 120.5])->assertCreated()->json('data.proposal');
        $this->assertSame('update_budget', $budget['action']);
        $this->assertSame(12050, $budget['payload']['daily_budget_minor']);

        $status = $this->operate($campaign, ['action' => 'set_status', 'reason' => 'نوقف', 'status' => 'paused'])->assertCreated()->json('data.proposal');
        $this->assertSame('pause', $status['action']);

        // Saying what it already is changes nothing and is not another proposal.
        $this->operate($campaign, ['action' => 'resume', 'reason' => 'x'])->assertStatus(422);
    }

    public function test_there_is_no_delete_and_no_way_to_ask_for_one(): void
    {
        $campaign = $this->campaign('لا حذف', 100_000, 3);

        foreach (['delete', 'archive', 'remove', 'destroy'] as $action) {
            $this->operate($campaign, ['action' => $action, 'reason' => 'x'])->assertStatus(422);
        }

        $this->actingAs($this->t['admin'])->deleteJson("/api/v1/campaigns/{$campaign->uuid}")->assertStatus(405);
        $this->assertNotContains('delete', ActionPolicy::ACTIONS);
        $this->assertNotNull($this->asTenant($this->t['tenant'], fn () => Campaign::find($campaign->id)));
    }

    public function test_only_those_who_run_campaigns_may(): void
    {
        $campaign = $this->campaign('للصلاحيات', 100_000, 3);

        foreach ([Role::Sales, Role::Finance, Role::ClientViewer] as $role) {
            $this->operate($campaign, ['action' => 'pause', 'reason' => 'x'], $this->makeUser($this->t['tenant'], $role))->assertForbidden();
        }

        $marketing = $this->makeUser($this->t['tenant'], Role::MarketingManager);
        $this->operate($campaign, ['action' => 'pause', 'reason' => 'x'], $marketing)->assertCreated();

        // And only the workspace's own campaigns.
        $other = $this->provisionTenant('Elsewhere');
        $this->operate($campaign, ['action' => 'pause', 'reason' => 'x'], $other['admin'])->assertNotFound();
    }

    public function test_with_no_account_connected_an_approved_change_is_refused(): void
    {
        $campaign = $this->campaign('بلا حساب', 100_000, 3);
        $proposal = $this->operate($campaign, ['action' => 'pause', 'reason' => 'x'])->assertCreated()->json('data.proposal');

        // No sandbox: approving finds no connection and refuses — nothing is invented.
        $this->actingAs($this->t['admin'])->postJson("/api/v1/action-proposals/{$proposal['id']}/approve")->assertOk();

        $this->asTenant($this->t['tenant'], function () use ($campaign) {
            $this->assertSame('blocked', ActionProposal::first()->status);
            $this->assertSame('active', $campaign->fresh()->status);
            $this->assertSame(0, ActionLogEntry::count());
        });
        $this->assertFalse($this->actingAs($this->t['admin'])->getJson('/api/v1/campaigns/operations')->json('data.sandbox'));
        $this->assertFalse(collect($this->actingAs($this->t['admin'])->getJson('/api/v1/action-proposals')->json('data.proposals'))->firstWhere('id', $proposal['id'])['sandbox']);
    }

    public function test_in_the_sandbox_an_approved_change_is_recorded_not_sent(): void
    {
        // (A router keeps the controller it built for a route, so the sandbox is switched on before the first request.)
        config(['ads.sandbox' => true]);
        $campaign = $this->campaign('في الصندوق', 100_000, 3);

        $this->assertTrue($this->actingAs($this->t['admin'])->getJson('/api/v1/campaigns/operations')->json('data.sandbox'));

        $filed = $this->operate($campaign, ['action' => 'pause', 'reason' => 'x'])->assertCreated()->json('data.proposal');
        $this->actingAs($this->t['admin'])->postJson("/api/v1/action-proposals/{$filed['id']}/approve")->assertOk();

        $this->asTenant($this->t['tenant'], function () use ($campaign, $filed) {
            $this->assertSame('executed', ActionProposal::where('uuid', $filed['id'])->first()->status);
            $this->assertSame('paused', $campaign->fresh()->status, 'our copy follows');
            $entry = ActionLogEntry::first();
            $this->assertTrue($entry->ok);
            $this->assertTrue($entry->response['sandbox']);
            $this->assertTrue($entry->request['sandbox']);
        });
        Http::assertNothingSent();

        $listed = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/action-proposals')->json('data.proposals'))->firstWhere('id', $filed['id']);
        $this->assertTrue($listed['sandbox'], 'the inbox says it was the stand-in');

        // A change the stand-in cannot imitate is refused, not pretended.
        $second = $this->campaign('أخرى', 100_000, 3);
        $adapter = app(AdPlatformRegistry::class)->get('meta');
        $this->assertInstanceOf(SandboxAdapter::class, $adapter);
        $this->assertFalse($adapter->execute(new AdConnection, 'create_ad', ['external_id' => 'x'], [])['ok']);
        $this->assertNotNull($second);
    }

    public function test_sync_reads_so_it_needs_no_approval_and_says_when_there_is_nothing_to_read_from(): void
    {
        $campaign = $this->campaign('للمزامنة', 100_000, 3);

        $result = $this->operate($campaign, ['action' => 'sync'])->assertOk()->json('data');

        $this->assertSame('sync', $result['kind']);
        $this->assertFalse($result['synced']);
        $this->assertSame('not_connected', $result['reason']);
        $this->asTenant($this->t['tenant'], fn () => $this->assertSame(0, ActionProposal::count()));
    }

    public function test_tracking_health_says_whether_the_numbers_can_be_believed(): void
    {
        $campaign = $this->campaign('للتتبع', 100_000, 10);

        // Half the leads carry nothing that says where they came from; nothing tracks the campaign; a conversion failed.
        $this->asTenant($this->t['tenant'], function () use ($campaign) {
            $ids = Lead::where('campaign_ref_id', $campaign->id)->pluck('id')->take(5);
            DB::table('leads')->whereIn('id', $ids)->update(['utm_source' => null]);
            ConversionExport::create(['lead_id' => $ids->first(), 'platform' => 'meta', 'event' => 'qualified', 'status' => 'failed', 'occurred_at' => now()]);
        });

        $health = $this->actingAs($this->t['admin'])->getJson("/api/v1/campaigns/{$campaign->uuid}/tracking-health")->assertOk()->json('data');

        $this->assertSame(10, $health['leads']['total']);
        $this->assertSame(50, $health['leads']['traced_percent']);
        $this->assertSame(0, $health['links']);
        $this->assertSame(1, $health['conversions']['failed']);
        $this->assertEqualsCanonicalizing(['low_trace', 'no_links', 'conversions_failing'], $health['issues']);
        $this->assertSame('risk', $health['status']);

        // A link for it and traceable leads: healthier.
        $this->asTenant($this->t['tenant'], function () use ($campaign) {
            TrackingLink::create(['name' => 'رابط', 'channel' => 'meta', 'token' => 'abc12345', 'campaign_id' => $campaign->id, 'is_active' => true]);
            DB::table('leads')->where('campaign_ref_id', $campaign->id)->update(['utm_source' => 'meta']);
            ConversionExport::query()->update(['status' => 'sent']);
        });
        $after = $this->actingAs($this->t['admin'])->getJson("/api/v1/campaigns/{$campaign->uuid}/tracking-health")->json('data');
        $this->assertSame([], $after['issues']);
        $this->assertSame('healthy', $after['status']);
    }

    public function test_the_project_a_campaign_belongs_to_travels_with_its_row(): void
    {
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
        $campaign = $this->campaign('مشروع', 100_000, 3, 0, 'meta', ['project_id' => $project->id, 'objective' => 'leads']);

        $row = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/campaigns/operations')->json('data.campaigns'))->firstWhere('id', $campaign->uuid);

        $this->assertSame('النرجس', $row['project']['name']);
        $this->assertSame('leads', $row['objective']);
    }
}
