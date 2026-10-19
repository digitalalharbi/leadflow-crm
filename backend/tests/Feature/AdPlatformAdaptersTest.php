<?php

namespace Tests\Feature;

use App\Domain\Campaigns\AdPlatformRegistry;
use App\Domain\Campaigns\CampaignSyncer;
use App\Models\Ad;
use App\Models\AdConnection;
use App\Models\AdSet;
use App\Models\Campaign;
use App\Models\MetricSnapshot;
use App\Support\OAuth1;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Each adapter against the response shapes its platform documents. These are
 * code verification only — a faked HTTP layer proves the mapping, never that a
 * live account works, and nothing here touches the verification ladder.
 */
class AdPlatformAdaptersTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Adapters Co');
    }

    private function connect(string $platform, array $credentials, array $config = []): AdConnection
    {
        return $this->asTenant($this->t['tenant'], fn () => AdConnection::create(['platform' => $platform, 'credentials' => $credentials, 'config' => $config]));
    }

    private function sync(AdConnection $c): array
    {
        return $this->asTenant($this->t['tenant'], fn () => app(CampaignSyncer::class)->sync($c));
    }

    private function execute(string $platform, AdConnection $c, string $action, array $target, array $payload = []): array
    {
        return $this->asTenant($this->t['tenant'], fn () => app(AdPlatformRegistry::class)->get($platform)->execute($c, $action, $target, $payload));
    }

    private function assertSynced(int $spendMinor, int $leads): void
    {
        $this->asTenant($this->t['tenant'], function () use ($spendMinor, $leads) {
            $this->assertSame(1, Campaign::count());
            $this->assertSame(1, AdSet::count());
            $this->assertSame(1, Ad::count());
            $snap = MetricSnapshot::first();
            $this->assertNotNull($snap, 'No metrics were stored.');
            $this->assertSame($spendMinor, $snap->spend_minor);
            $this->assertSame($leads, $snap->leads);
            $this->assertNotNull($snap->ad_id);
        });
    }

    // ------------------------------------------------------------- TikTok

    public function test_tiktok_reads_the_hierarchy_and_daily_report(): void
    {
        $ok = fn (array $data) => Http::response(['code' => 0, 'message' => 'OK', 'data' => $data]);
        $day = now()->toDateString();

        Http::fake([
            '*/advertiser/info/*' => $ok(['list' => [['advertiser_id' => '700', 'name' => 'حساب', 'currency' => 'SAR', 'status' => 'STATUS_ENABLE']]]),
            '*/campaign/get/*' => $ok(['list' => [['campaign_id' => 'c1', 'campaign_name' => 'حملة', 'operation_status' => 'ENABLE', 'budget_mode' => 'BUDGET_MODE_DAY', 'budget' => 250.5]], 'page_info' => ['total_page' => 1]]),
            '*/adgroup/get/*' => $ok(['list' => [['adgroup_id' => 'g1', 'campaign_id' => 'c1', 'adgroup_name' => 'مجموعة', 'operation_status' => 'ENABLE']], 'page_info' => ['total_page' => 1]]),
            '*/ad/get/*' => $ok(['list' => [['ad_id' => 'a1', 'adgroup_id' => 'g1', 'campaign_id' => 'c1', 'ad_name' => 'فيديو', 'operation_status' => 'DISABLE', 'video_id' => 'v1', 'ad_text' => 'نص']], 'page_info' => ['total_page' => 1]]),
            '*/report/integrated/get/*' => $ok(['list' => [['dimensions' => ['ad_id' => 'a1', 'stat_time_day' => "{$day} 00:00:00"], 'metrics' => ['campaign_id' => 'c1', 'adgroup_id' => 'g1', 'spend' => '42.10', 'impressions' => '900', 'clicks' => '30', 'conversion' => '4', 'complete_payment' => '0', 'complete_payment_roas' => '0']]], 'page_info' => ['total_page' => 1]]),
        ]);

        $result = $this->sync($this->connect('tiktok', ['access_token' => 't'], ['account_ids' => ['700']]));

        $this->assertNull($result['error']);
        $this->assertSynced(4210, 4);
        $this->asTenant($this->t['tenant'], fn () => $this->assertSame(25050, Campaign::first()->daily_budget_minor));
        $this->assertSame('paused', $this->asTenant($this->t['tenant'], fn () => Ad::first()->status));
    }

    public function test_tiktok_reads_failure_from_its_code_not_the_status_line(): void
    {
        Http::fake(['*' => Http::response(['code' => 40105, 'message' => 'Access token is incorrect'])]);

        $result = $this->sync($this->connect('tiktok', ['access_token' => 't'], ['account_ids' => ['700']]));

        $this->assertStringContainsString('Access token is incorrect', $result['error']);
    }

    public function test_tiktok_pauses_and_rebudgets_at_the_right_level(): void
    {
        Http::fake(['*' => Http::response(['code' => 0, 'message' => 'OK', 'data' => []])]);
        $c = $this->connect('tiktok', ['access_token' => 't']);

        $this->assertTrue($this->execute('tiktok', $c, 'pause', ['level' => 'ad_set', 'external_id' => 'g1', 'account_external_id' => '700'])['ok']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/adgroup/status/update/') && $r['adgroup_ids'] === ['g1'] && $r['operation_status'] === 'DISABLE');

        $this->execute('tiktok', $c, 'update_budget', ['level' => 'campaign', 'external_id' => 'c1', 'account_external_id' => '700'], ['daily_budget_minor' => 30000]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/campaign/update/') && (float) $r['budget'] === 300.0);

        $refused = $this->execute('tiktok', $c, 'duplicate_ad_set', ['level' => 'ad_set', 'external_id' => 'g1', 'account_external_id' => '700']);
        $this->assertFalse($refused['ok']);
    }

    // ------------------------------------------------------------- Google Ads

    public function test_google_ads_mints_a_token_and_reads_through_gaql(): void
    {
        $day = now()->toDateString();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
            '*googleAds:searchStream' => function (Request $r) use ($day) {
                $q = $r['query'];
                $rows = match (true) {
                    str_contains($q, 'FROM customer') => [['customer' => ['id' => '111', 'descriptiveName' => 'حساب', 'currencyCode' => 'SAR', 'manager' => false, 'status' => 'ENABLED']]],
                    str_contains($q, 'FROM campaign') => [['campaign' => ['id' => '5', 'name' => 'بحث', 'status' => 'ENABLED', 'advertisingChannelType' => 'SEARCH'], 'campaignBudget' => ['amountMicros' => '50000000']]],
                    str_contains($q, 'FROM ad_group_ad') && str_contains($q, 'segments.date') => [['campaign' => ['id' => '5'], 'adGroup' => ['id' => '6'], 'adGroupAd' => ['ad' => ['id' => '7']], 'segments' => ['date' => $day], 'metrics' => ['costMicros' => '12340000', 'impressions' => '500', 'clicks' => '20', 'conversions' => 3.0, 'conversionsValue' => 0]]],
                    str_contains($q, 'FROM ad_group_ad') => [['campaign' => ['id' => '5'], 'adGroup' => ['id' => '6'], 'adGroupAd' => ['status' => 'ENABLED', 'ad' => ['id' => '7', 'responsiveSearchAd' => ['headlines' => [['text' => 'شقق للبيع']]]]]]],
                    str_contains($q, 'FROM ad_group') => [['campaign' => ['id' => '5'], 'adGroup' => ['id' => '6', 'name' => 'مجموعة', 'status' => 'ENABLED']]],
                    default => [],
                };

                return Http::response([['results' => $rows]]);
            },
        ]);

        $result = $this->sync($this->connect('google_ads', ['developer_token' => 'd', 'client_id' => 'c', 'client_secret' => 's', 'refresh_token' => 'r'], ['account_ids' => ['111']]));

        $this->assertNull($result['error']);
        $this->assertSynced(1234, 3);
        $this->asTenant($this->t['tenant'], fn () => $this->assertSame(5000, Campaign::first()->daily_budget_minor));
        Http::assertSent(fn (Request $r) => $r->hasHeader('developer-token', 'd') && $r->hasHeader('Authorization', 'Bearer ya29.test'));
    }

    public function test_google_ads_pauses_an_ad_by_its_full_resource_name(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test']),
            '*:mutate' => Http::response(['results' => [['resourceName' => 'x']]]),
        ]);
        $c = $this->connect('google_ads', ['developer_token' => 'd', 'client_id' => 'c', 'client_secret' => 's', 'refresh_token' => 'r']);

        $this->assertTrue($this->execute('google_ads', $c, 'pause', ['level' => 'ad', 'external_id' => '7', 'account_external_id' => '111', 'parent_external_id' => '6'])['ok']);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/customers/111/adGroupAds:mutate')
            && $r['operations'][0]['update']['resourceName'] === 'customers/111/adGroupAds/6~7'
            && $r['operations'][0]['updateMask'] === 'status');
    }

    // ------------------------------------------------------------- Snapchat

    public function test_snapchat_reads_organisations_squads_and_day_stats(): void
    {
        $day = now()->toDateString();

        Http::fake([
            '*/me/organizations*' => Http::response(['organizations' => [['organization' => ['ad_accounts' => [['id' => 'acc', 'name' => 'سناب', 'currency' => 'SAR', 'status' => 'ACTIVE']]]]]]),
            '*/adaccounts/acc/campaigns*' => Http::response(['campaigns' => [['campaign' => ['id' => 'c1', 'name' => 'حملة', 'status' => 'ACTIVE', 'daily_budget_micro' => 100000000]]]]),
            '*/adaccounts/acc/adsquads*' => Http::response(['adsquads' => [['adsquad' => ['id' => 's1', 'campaign_id' => 'c1', 'name' => 'مجموعة', 'status' => 'ACTIVE']]]]),
            '*/adaccounts/acc/creatives*' => Http::response(['creatives' => [['creative' => ['id' => 'cr1', 'name' => 'إعلان', 'type' => 'SNAP_AD', 'headline' => 'عنوان']]]]),
            '*/adaccounts/acc/ads*' => Http::response(['ads' => [['ad' => ['id' => 'a1', 'ad_squad_id' => 's1', 'name' => 'إعلان', 'status' => 'ACTIVE', 'creative_id' => 'cr1']]]]),
            '*/adaccounts/acc/stats*' => Http::response(['timeseries_stats' => [['timeseries_stat' => ['breakdown_stats' => ['ad' => [['id' => 'a1', 'timeseries' => [['start_time' => "{$day}T00:00:00.000-00:00", 'stats' => ['impressions' => 800, 'swipes' => 25, 'spend' => 33000000, 'conversion_sign_ups' => 2]]]]]]]]]]),
        ]);

        $result = $this->sync($this->connect('snapchat', ['access_token' => 't']));

        $this->assertNull($result['error']);
        $this->assertSynced(3300, 2);
    }

    public function test_snapchat_changes_one_field_and_puts_the_whole_object_back(): void
    {
        Http::fake([
            '*/campaigns/c1' => Http::response(['campaigns' => [['campaign' => ['id' => 'c1', 'name' => 'حملة', 'status' => 'ACTIVE', 'ad_account_id' => 'acc', 'start_time' => '2026-01-01T00:00:00Z']]]]),
            '*/adaccounts/acc/campaigns' => Http::response(['request_status' => 'SUCCESS']),
        ]);
        $c = $this->connect('snapchat', ['access_token' => 't']);

        $this->assertTrue($this->execute('snapchat', $c, 'pause', ['level' => 'campaign', 'external_id' => 'c1', 'account_external_id' => 'acc'])['ok']);

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && $r['campaigns'][0]['status'] === 'PAUSED'
            && $r['campaigns'][0]['name'] === 'حملة'
            && $r['campaigns'][0]['start_time'] === '2026-01-01T00:00:00Z');
    }

    // ------------------------------------------------------------- X

    public function test_oauth1_signs_exactly_as_the_specification_example(): void
    {
        $header = OAuth1::header(
            'POST', 'https://api.twitter.com/1.1/statuses/update.json',
            ['include_entities' => 'true', 'status' => 'Hello Ladies + Gentlemen, a signed OAuth request!'],
            'xvz1evFS4wEEPTGEFPHBog', 'kAcSOqF21Fu85e7zjz7ZN2U4ZRhfV3WpwPAoE3Z7kBw',
            '370773112-GmHxMAgYyLbNEtIKZeRNFsMKPR9EyMZeS9weJAEb', 'LswwdoUaIvS8ltyTt5jkRh4J50vUPVVHtR2YPi5kE',
            'kYjzVBB8Y0ZFabxSWbWovY3uYSQ2pTgmZeNu2VS4cg', 1318622958,
        );

        $this->assertStringContainsString('oauth_signature="hCtSmYh%2BiHYCEqBWrE7C7hYmtUk%3D"', $header);
    }

    public function test_x_reads_accounts_line_items_and_day_aligned_stats(): void
    {
        Http::fake([
            '*/12/accounts?*' => Http::response(['data' => [['id' => 'xa', 'name' => 'إكس', 'approval_status' => 'ACCEPTED']]]),
            '*/accounts/xa/campaigns*' => Http::response(['data' => [['id' => 'c1', 'name' => 'حملة', 'entity_status' => 'ACTIVE', 'daily_budget_amount_local_micro' => 20000000]]]),
            '*/accounts/xa/line_items*' => Http::response(['data' => [['id' => 'l1', 'campaign_id' => 'c1', 'name' => 'بند', 'entity_status' => 'ACTIVE']]]),
            '*/accounts/xa/promoted_tweets*' => Http::response(['data' => [['id' => 'p1', 'line_item_id' => 'l1', 'tweet_id' => '99', 'entity_status' => 'ACTIVE']]]),
            '*/stats/accounts/xa*' => Http::response(['data' => [['id' => 'p1', 'id_data' => [['metrics' => ['impressions' => [700], 'clicks' => [14], 'billed_charge_local_micro' => [5500000], 'conversion_sign_ups' => ['metric' => [1]]]]]]]]),
        ]);

        $result = $this->sync($this->connect('x', ['consumer_key' => 'a', 'consumer_secret' => 'b', 'access_token' => 'c', 'access_token_secret' => 'd']));

        $this->assertNull($result['error']);
        $this->assertSynced(550, 1);
        Http::assertSent(fn (Request $r) => str_starts_with($r->header('Authorization')[0] ?? '', 'OAuth '));
    }

    // ------------------------------------------------------------- LinkedIn

    public function test_linkedin_maps_groups_campaigns_and_creatives_and_reads_analytics(): void
    {
        $now = now();

        Http::fake([
            '*/adAccounts?q=search*' => Http::response(['elements' => [['id' => 501, 'name' => 'لينكدإن', 'currency' => 'SAR', 'status' => 'ACTIVE']]]),
            '*/adAccounts/501/adCampaignGroups*' => Http::response(['elements' => [['id' => 10, 'name' => 'مجموعة حملات', 'status' => 'ACTIVE']]]),
            '*/adAccounts/501/adCampaigns*' => Http::response(['elements' => [['id' => 20, 'name' => 'حملة', 'status' => 'ACTIVE', 'campaignGroup' => 'urn:li:sponsoredCampaignGroup:10', 'dailyBudget' => ['amount' => '75.00', 'currencyCode' => 'SAR']]]]),
            '*/adAccounts/501/creatives*' => Http::response(['elements' => [['id' => 'urn:li:sponsoredCreative:30', 'campaign' => 'urn:li:sponsoredCampaign:20', 'intendedStatus' => 'ACTIVE']]]),
            '*/adAnalytics*' => Http::response(['elements' => [['pivotValues' => ['urn:li:sponsoredCreative:30'], 'dateRange' => ['start' => ['year' => $now->year, 'month' => $now->month, 'day' => $now->day]], 'costInLocalCurrency' => '19.99', 'impressions' => 400, 'clicks' => 9, 'oneClickLeads' => 2]]]),
        ]);

        $result = $this->sync($this->connect('linkedin', ['access_token' => 't']));

        $this->assertNull($result['error']);
        $this->assertSynced(1999, 2);
        // Budget lives on LinkedIn's campaign, which is our ad set.
        $this->asTenant($this->t['tenant'], fn () => $this->assertSame(7500, AdSet::first()->daily_budget_minor));
    }

    public function test_linkedin_writes_are_partial_updates_of_one_field(): void
    {
        Http::fake(['*' => Http::response([], 204)]);
        $c = $this->connect('linkedin', ['access_token' => 't']);

        $this->assertTrue($this->execute('linkedin', $c, 'update_budget', ['level' => 'ad_set', 'external_id' => '20', 'account_external_id' => '501'], ['daily_budget_minor' => 9000, 'currency' => 'SAR'])['ok']);

        Http::assertSent(fn (Request $r) => $r->hasHeader('X-RestLi-Method', 'PARTIAL_UPDATE')
            && str_ends_with($r->url(), '/adAccounts/501/adCampaigns/20')
            && $r['patch']['$set']['dailyBudget']['amount'] === '90.00');
    }

    // ------------------------------------------------------------- Meta

    public function test_meta_copies_an_ad_set_and_creates_campaigns_paused(): void
    {
        Http::fake(['*' => Http::response(['copied_adset_id' => '777', 'id' => '888', 'success' => true])]);
        $c = $this->connect('meta', ['access_token' => 't']);

        $copy = $this->execute('meta', $c, 'duplicate_ad_set', ['level' => 'ad_set', 'external_id' => 's1', 'account_external_id' => '999']);
        $this->assertSame('777', $copy['created_external_id']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/s1/copies') && $r['status_option'] === 'PAUSED');

        $created = $this->execute('meta', $c, 'create_campaign', ['level' => null, 'external_id' => null, 'account_external_id' => '999'], ['name' => 'جديدة']);
        $this->assertSame('888', $created['created_external_id']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/act_999/campaigns') && $r['status'] === 'PAUSED');
    }

    public function test_every_adapter_lists_every_action_as_supported_or_not(): void
    {
        foreach (app(AdPlatformRegistry::class)->all() as $adapter) {
            $caps = $adapter->capabilities();
            $this->assertCount(9, $caps, $adapter->platform());
            $this->assertNotContains('delete', array_keys($caps));
            foreach ($caps as $action => $cap) {
                $this->assertSame($cap['supported'], in_array($action, $adapter->actions(), true));
            }
        }
    }
}
