<?php

namespace Tests\Feature;

use App\DataTransferObjects\IncomingLead;
use App\Domain\Campaigns\CampaignResolver;
use App\Domain\Campaigns\CampaignSyncer;
use App\Enums\IntegrationProvider;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\Campaign;
use App\Models\CampaignSpend;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\MetricSnapshot;
use App\Services\Leads\LeadIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CampaignManagementTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Campaign Co');
    }

    private function integration(string $provider = 'meta', array $credentials = ['access_token' => 'tok_test']): Integration
    {
        return $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => $provider, 'name' => ucfirst($provider), 'status' => 'connected',
            'credentials' => $credentials, 'is_active' => true,
        ]));
    }

    private function fakeMeta(string $spend = '125.50'): void
    {
        // A fresh client: stubs registered earlier win over later ones, so a second
        // fake in the same test would otherwise be ignored.
        Http::swap(new Factory);

        Http::fake([
            'graph.facebook.com/*/me/adaccounts*' => Http::response(['data' => [
                ['account_id' => '999', 'name' => 'حساب الاختبار', 'currency' => 'SAR', 'account_status' => 1],
            ]]),
            'graph.facebook.com/*/act_999/campaigns*' => Http::response(['data' => [
                ['id' => 'c1', 'name' => 'حملة النرجس', 'effective_status' => 'ACTIVE', 'objective' => 'OUTCOME_LEADS', 'daily_budget' => '50000'],
            ]]),
            'graph.facebook.com/*/act_999/adsets*' => Http::response(['data' => [
                ['id' => 's1', 'name' => 'جمهور الرياض', 'campaign_id' => 'c1', 'effective_status' => 'ACTIVE', 'daily_budget' => '25000'],
            ]]),
            'graph.facebook.com/*/act_999/ads*' => Http::response(['data' => [
                ['id' => 'a1', 'name' => 'فيديو ١', 'campaign_id' => 'c1', 'adset_id' => 's1', 'effective_status' => 'ACTIVE',
                    'creative' => ['id' => 'cr1', 'title' => 'عنوان', 'body' => 'نص', 'object_type' => 'VIDEO', 'thumbnail_url' => 'https://example.test/t.jpg']],
            ]]),
            'graph.facebook.com/*/act_999/insights*' => Http::response(['data' => [
                ['campaign_id' => 'c1', 'adset_id' => 's1', 'ad_id' => 'a1', 'date_start' => now()->toDateString(),
                    'spend' => $spend, 'impressions' => '10000', 'clicks' => '250',
                    'actions' => [['action_type' => 'lead', 'value' => '12'], ['action_type' => 'link_click', 'value' => '250']],
                    'action_values' => []],
            ]]),
        ]);
    }

    public function test_a_meta_account_syncs_into_campaigns_ads_creatives_and_daily_snapshots(): void
    {
        $this->fakeMeta();
        $integration = $this->integration();

        $result = $this->asTenant($this->t['tenant'], fn () => app(CampaignSyncer::class)->sync($integration));

        $this->assertNull($result['error']);
        $this->assertSame(1, $result['accounts']);

        $this->asTenant($this->t['tenant'], function () {
            $campaign = Campaign::first();
            $this->assertSame('sync', $campaign->origin);
            $this->assertSame('active', $campaign->status);
            $this->assertSame(50000, $campaign->daily_budget_minor);
            $this->assertSame('SAR', $campaign->currency);

            $ad = Ad::first();
            $this->assertSame('video', $ad->creative->format);

            $snapshot = MetricSnapshot::first();
            // 125.50 in the account's currency, held as minor units.
            $this->assertSame(12550, $snapshot->spend_minor);
            $this->assertSame(12, $snapshot->leads);
            $this->assertSame(250, $snapshot->clicks);
        });
    }

    public function test_syncing_twice_corrects_rather_than_duplicates(): void
    {
        $integration = $this->integration();

        $this->fakeMeta('100.00');
        $this->asTenant($this->t['tenant'], fn () => app(CampaignSyncer::class)->sync($integration));

        $this->fakeMeta('150.00');
        $this->asTenant($this->t['tenant'], fn () => app(CampaignSyncer::class)->sync($integration));

        $this->asTenant($this->t['tenant'], function () {
            $this->assertSame(1, Campaign::count());
            $this->assertSame(1, MetricSnapshot::count());
            $this->assertSame(15000, MetricSnapshot::first()->spend_minor);
        });
    }

    public function test_a_campaign_that_leaves_the_platform_is_archived_not_deleted(): void
    {
        $integration = $this->integration();
        $this->fakeMeta();
        $this->asTenant($this->t['tenant'], fn () => app(CampaignSyncer::class)->sync($integration));

        Http::swap(new Factory);
        Http::fake([
            'graph.facebook.com/*/me/adaccounts*' => Http::response(['data' => [['account_id' => '999', 'currency' => 'SAR', 'account_status' => 1]]]),
            'graph.facebook.com/*/act_999/campaigns*' => Http::response(['data' => []]),
            'graph.facebook.com/*/act_999/adsets*' => Http::response(['data' => []]),
            'graph.facebook.com/*/act_999/ads*' => Http::response(['data' => []]),
            'graph.facebook.com/*/act_999/insights*' => Http::response(['data' => []]),
        ]);
        $this->asTenant($this->t['tenant'], fn () => app(CampaignSyncer::class)->sync($integration));

        $this->asTenant($this->t['tenant'], function () {
            $this->assertSame(1, Campaign::count());
            $this->assertNotNull(Campaign::first()->archived_at);
        });
    }

    public function test_a_failing_platform_is_reported_and_last_synced_is_not_moved(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Token expired']], 401)]);
        $integration = $this->integration();

        $result = $this->asTenant($this->t['tenant'], fn () => app(CampaignSyncer::class)->sync($integration));

        $this->assertStringContainsString('Token expired', $result['error']);
        $this->assertSame(0, $result['accounts']);
        $this->assertNull($this->asTenant($this->t['tenant'], fn () => AdAccount::first()?->last_synced_at));
    }

    public function test_platforms_without_an_adapter_say_so_and_sync_nothing(): void
    {
        $this->integration('tiktok', ['access_token' => 'x']);

        $platforms = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/campaigns/platforms')->assertOk()->json('data.platforms'))->keyBy('key');

        $this->assertTrue($platforms['meta']['built']);
        $this->assertFalse($platforms['tiktok']['built']);
        $this->assertFalse($platforms['tiktok']['connected']);
        $this->assertSame([], $platforms['tiktok']['actions']);
        $this->assertCount(6, $platforms);

        $this->actingAs($this->t['admin'])->postJson('/api/v1/campaigns/sync')->assertOk()->assertJsonPath('data.accounts', 0);
    }

    public function test_leads_are_attributed_to_one_campaign_ad_set_and_ad(): void
    {
        $ingest = fn (string $phone, array $extra = []) => $this->asTenant($this->t['tenant'], fn () => app(LeadIngestionService::class)->ingest(
            $this->t['tenant'],
            IncomingLead::fromArray(IntegrationProvider::Manual, array_merge([
                'full_name' => 'عميل '.$phone, 'phone' => $phone, 'platform' => 'facebook',
                'campaign_name' => 'حملة النرجس', 'adset_name' => 'جمهور الرياض', 'ad_name' => 'فيديو ١',
            ], $extra)),
        ));

        $ingest('0551110001');
        $ingest('0551110002');
        $ingest('0551110003', ['platform' => 'website', 'campaign_name' => 'مباشر']); // not an ad platform

        $this->asTenant($this->t['tenant'], function () {
            // Facebook leads belong to a Meta campaign, once.
            $this->assertSame(1, Campaign::count());
            $campaign = Campaign::first();
            $this->assertSame('meta', $campaign->platform);
            $this->assertSame('lead_attribution', $campaign->origin);
            $this->assertSame(2, $campaign->leads()->count());
            $this->assertSame(1, Ad::count());
            $this->assertSame(1, Lead::whereNull('campaign_ref_id')->count());
        });
    }

    public function test_a_campaign_first_seen_by_name_is_promoted_when_sync_brings_its_id(): void
    {
        $this->asTenant($this->t['tenant'], fn () => app(LeadIngestionService::class)->ingest(
            $this->t['tenant'],
            IncomingLead::fromArray(IntegrationProvider::Manual, [
                'full_name' => 'عميل', 'phone' => '0551110009', 'platform' => 'facebook', 'campaign_name' => 'حملة النرجس',
            ]),
        ));

        $this->fakeMeta();
        $integration = $this->integration();
        $this->asTenant($this->t['tenant'], fn () => app(CampaignSyncer::class)->sync($integration));

        $this->asTenant($this->t['tenant'], function () {
            $this->assertSame(1, Campaign::count(), 'The synced campaign duplicated the one the leads revealed.');
            $campaign = Campaign::first();
            $this->assertSame('c1', $campaign->external_id);
            $this->assertSame('sync', $campaign->origin);
            $this->assertSame(1, $campaign->leads()->count());
        });
    }

    public function test_the_list_prices_leads_and_keeps_unknowns_null(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            $costed = Campaign::create(['platform' => 'meta', 'external_id' => 'c1', 'name' => 'مُكلَّفة', 'origin' => 'sync', 'status' => 'active']);
            $free = Campaign::create(['platform' => 'meta', 'name' => 'بلا تكلفة']);

            MetricSnapshot::create([
                'campaign_id' => $costed->id, 'platform' => 'meta', 'level' => 'campaign', 'date' => now()->toDateString(),
                'spend_minor' => 100000, 'impressions' => 5000, 'clicks' => 100,
            ]);

            foreach ([['qualified', 0], ['won', 0], ['new', 0]] as $i => [$status]) {
                $lead = Lead::create(['full_name' => 'ل'.$i, 'phone_normalized' => '+96655000'.$i.'111', 'status' => $status]);
                Lead::query()->whereKey($lead->id)->update(['campaign_ref_id' => $costed->id]);
            }
            $lead = Lead::create(['full_name' => 'ح', 'phone_normalized' => '+966550009999', 'status' => 'new']);
            Lead::query()->whereKey($lead->id)->update(['campaign_ref_id' => $free->id]);
        });

        $data = $this->actingAs($this->t['admin'])->getJson('/api/v1/campaigns')->assertOk()->json('data');
        $rows = collect($data['campaigns'])->keyBy('name');

        $costed = $rows['مُكلَّفة'];
        $this->assertEquals(1000.0, $costed['spend']);
        $this->assertSame('platform', $costed['spend_source']);
        $this->assertSame(3, $costed['leads']);
        $this->assertSame(2, $costed['qualified']);           // qualified + won
        $this->assertEqualsWithDelta(333.33, $costed['cpl'], 0.01);
        $this->assertEquals(500.0, $costed['cpql']);
        $this->assertEquals(1000.0, $costed['cpa']);
        // One sale, nobody typed its price: no return can be computed.
        $this->assertNull($costed['roas']);
        $this->assertEquals(2.0, $costed['ctr']);

        $free = $rows['بلا تكلفة'];
        $this->assertNull($free['spend']);
        $this->assertNull($free['cpl']);
        $this->assertSame(1, $free['leads']);
    }

    public function test_typed_in_spend_stands_in_until_an_account_reports(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            Campaign::create(['platform' => 'meta', 'name' => 'يدوية']);
            CampaignSpend::create(['platform' => 'meta', 'campaign_name' => 'يدوية', 'spend_date' => now()->toDateString(), 'amount_minor' => 42000, 'currency' => 'SAR']);
        });

        $row = $this->actingAs($this->t['admin'])->getJson('/api/v1/campaigns')->json('data.campaigns.0');

        $this->assertEquals(420.0, $row['spend']);
        $this->assertSame('manual', $row['spend_source']);
    }

    public function test_one_workspace_never_sees_anothers_campaigns(): void
    {
        $other = $this->provisionTenant('Other Co', ['admin_email' => 'other@example.test']);
        $this->asTenant($other['tenant'], fn () => Campaign::create(['platform' => 'meta', 'name' => 'سرية']));
        $this->asTenant($this->t['tenant'], fn () => Campaign::create(['platform' => 'meta', 'name' => 'لي']));

        $names = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/campaigns')->json('data.campaigns'))->pluck('name');

        $this->assertSame(['لي'], $names->all());
    }

    public function test_a_sales_rep_cannot_read_campaign_money(): void
    {
        $rep = $this->makeUser($this->t['tenant']);

        $this->actingAs($rep)->getJson('/api/v1/campaigns')->assertForbidden();
        $this->actingAs($rep)->postJson('/api/v1/campaigns/sync')->assertForbidden();
    }

    public function test_a_campaign_opens_into_its_ads_their_quality_and_its_days(): void
    {
        $this->fakeMeta('200.00');
        $integration = $this->integration();
        $this->asTenant($this->t['tenant'], fn () => app(CampaignSyncer::class)->sync($integration));

        // Two leads through the same ad: one qualified.
        foreach (['0551112001' => 'qualified', '0551112002' => 'new'] as $phone => $status) {
            $this->asTenant($this->t['tenant'], function () use ($phone, $status) {
                $lead = Lead::create(['full_name' => 'ع', 'phone_normalized' => '+966'.substr($phone, 1), 'status' => $status,
                    'platform' => 'facebook', 'campaign_id' => 'c1', 'campaign_name' => 'حملة النرجس', 'adset_id' => 's1', 'ad_id' => 'a1']);
                app(CampaignResolver::class)->linkLead($lead);
            });
        }

        $uuid = $this->asTenant($this->t['tenant'], fn () => Campaign::first()->uuid);
        $data = $this->actingAs($this->t['admin'])->getJson("/api/v1/campaigns/{$uuid}")->assertOk()->json('data');

        $this->assertSame(2, $data['campaign']['leads']);
        $this->assertCount(1, $data['ads']);
        $this->assertSame(1, $data['ads'][0]['qualified']);
        $this->assertEquals(100.0, $data['ads'][0]['cpl']);
        $this->assertSame('video', $data['ads'][0]['creative']['format']);
        $this->assertSame(2, array_sum(array_column($data['leads_daily'], 'count')));
        $this->assertSame(2, array_sum(array_column($data['classification'], 'count')));
        $this->assertArrayHasKey('won', $data['feedback']);

        // Another workspace cannot open it.
        $other = $this->provisionTenant('Peek Co', ['admin_email' => 'peek@example.test']);
        $this->actingAs($other['admin'])->getJson("/api/v1/campaigns/{$uuid}")->assertNotFound();
    }
}
