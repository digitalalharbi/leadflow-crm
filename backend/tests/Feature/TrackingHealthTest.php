<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AdConnection;
use App\Models\ConversionExport;
use App\Models\Lead;
use App\Models\LeadSourceEvent;
use App\Models\TrackingEvent;
use App\Models\TrackingLink;
use App\Models\WebhookEndpoint;
use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Is tracking working — judged on what each platform delivered and what became of it. */
class TrackingHealthTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Health Co');
    }

    private function health(array $query = []): array
    {
        return $this->actingAs($this->t['admin'])->getJson('/api/v1/tracking/health?'.http_build_query($query))->assertOk()->json('data');
    }

    private function row(string $platform): array
    {
        return collect($this->health()['platforms'])->firstWhere('platform', $platform);
    }

    private function lead(string $platform, array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], function () use ($platform, $attributes) {
            $lead = Lead::create(['full_name' => 'عميل '.random_int(100, 999), 'phone' => '0551234567', 'phone_normalized' => '+9665512'.random_int(10000, 99999), 'platform' => $platform] + $attributes);

            return $lead;
        });
    }

    private function arrival(string $platform, bool $duplicate = false, $at = null): void
    {
        $lead = $this->lead($platform);
        $this->asTenant($this->t['tenant'], fn () => LeadSourceEvent::create(['lead_id' => $lead->id, 'provider' => $platform, 'platform' => $platform, 'was_duplicate' => $duplicate, 'received_at' => $at ?? now()]));
    }

    private function connect(string $platform, array $credentials = ['access_token' => 'tok', 'pixel_id' => '123']): void
    {
        $this->asTenant($this->t['tenant'], fn () => AdConnection::create(['platform' => $platform, 'credentials' => $credentials, 'is_active' => true]));
    }

    public function test_it_watches_the_eight_platforms_and_an_empty_workspace_is_idle_not_broken(): void
    {
        $data = $this->health();

        $this->assertSame(['meta', 'tiktok', 'snapchat', 'google_ads', 'x', 'linkedin', 'whatsapp', 'landing_page'], array_column($data['platforms'], 'platform'));
        $this->assertSame(0, $data['summary']['connected']);
        $this->assertSame(0, $data['summary']['at_risk']);
        $this->assertSame('idle', $data['platforms'][0]['status']);
        $this->assertNull($data['platforms'][0]['success_rate'], 'no deliveries is not a 100% success rate');
        $this->assertNull($data['summary']['unattributed_percent']);
        $this->assertSame([], $data['recommendations']);
    }

    public function test_a_platform_that_is_delivering_reports_its_last_event_and_is_healthy(): void
    {
        $this->connect('meta');
        $this->arrival('meta');
        $this->arrival('facebook');
        $this->lead('meta', ['utm_source' => 'meta', 'utm_campaign' => 'oct', 'campaign_name' => 'حملة']);

        $row = $this->row('meta');

        $this->assertTrue($row['connected']);
        $this->assertTrue($row['receiving']);
        $this->assertSame(2, $row['events'], 'facebook leads are reported under meta');
        $this->assertNotNull($row['last_event_at']);
        $this->assertEquals(100.0, $row['success_rate']);
        $this->assertCount(7, $row['trend']);
        $this->assertSame(2, array_sum($row['trend']));
    }

    public function test_a_connected_platform_gone_silent_is_a_risk_with_a_sentence_to_say_so(): void
    {
        $this->connect('tiktok');
        $this->arrival('tiktok', at: now()->subDays(5));

        $row = $this->row('tiktok');
        $this->assertFalse($row['receiving']);
        $this->assertSame('risk', $row['status']);

        $keys = array_column($this->health()['recommendations'], 'key');
        $this->assertContains('platform_silent', $keys);
    }

    public function test_refused_deliveries_pull_the_success_rate_down(): void
    {
        $this->connect('meta');
        foreach (range(1, 3) as $i) {
            $this->arrival('meta');
        }

        $endpoint = $this->asTenant($this->t['tenant'], fn () => WebhookEndpoint::where('tenant_id', $this->t['tenant']->id)->first());
        $this->asTenant($this->t['tenant'], function () use ($endpoint) {
            foreach (range(1, 2) as $i) {
                WebhookLog::create(['tenant_id' => $this->t['tenant']->id, 'webhook_endpoint_id' => $endpoint->id, 'provider' => 'meta', 'status' => 'rejected', 'http_status' => 401, 'error' => 'Invalid secret', 'received_at' => now()]);
            }
        });

        $row = $this->row('meta');

        $this->assertSame(2, $row['rejected']);
        $this->assertEquals(60.0, $row['success_rate'], '3 accepted of 5 attempts');
        $this->assertSame('risk', $row['status']);
        $this->assertContains('rejecting', array_column($this->health()['recommendations'], 'key'));
    }

    public function test_repeated_deliveries_are_counted_beside_arrivals_not_netted_out_of_them(): void
    {
        $this->connect('google_ads');
        $this->arrival('google_ads');
        $this->arrival('google_ads', duplicate: true);
        $this->arrival('google_ads', duplicate: true);

        $row = $this->row('google_ads');

        $this->assertSame(2, $row['duplicates']);
        $this->assertEquals(66.7, $row['duplicate_rate']);
        $this->assertSame('attention', $row['status']);
    }

    public function test_leads_without_utm_and_without_a_campaign_are_counted_apart(): void
    {
        $this->connect('meta');
        $this->arrival('meta');
        $this->lead('meta', ['utm_source' => 'meta', 'campaign_name' => 'حملة']);        // traced, has utm
        $this->lead('meta', ['campaign_name' => 'حملة']);                                // traced, no utm
        $this->lead('meta');                                                              // neither

        $row = $this->row('meta');

        $this->assertSame(4, $row['leads']);   // the arrival's own lead plus three
        $this->assertSame(3, $row['missing_utm']);
        $this->assertSame(2, $row['unattributed']);
        $this->assertEquals(50.0, $row['unattributed_percent']);
        $this->assertContains('unattributed', array_column($this->health()['recommendations'], 'key'));
    }

    public function test_a_lead_that_came_through_a_tracking_link_is_attributed(): void
    {
        $link = $this->asTenant($this->t['tenant'], fn () => TrackingLink::create(['token' => 'tokabc1234', 'name' => 'ل', 'channel' => 'meta']));
        foreach ([1, 2] as $i) {
            $lead = $this->lead('meta');
            $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['first_touch_link_id' => $link->id])->saveQuietly());
        }

        $this->assertSame(0, $this->row('meta')['unattributed']);
        $this->assertSame(0.0, (float) $this->health()['summary']['unattributed_percent']);
    }

    public function test_landing_pages_report_through_their_tracking_links(): void
    {
        $link = $this->asTenant($this->t['tenant'], fn () => TrackingLink::create(['token' => 'tokland123', 'name' => 'صفحة', 'channel' => 'landing_page']));
        $this->asTenant($this->t['tenant'], function () use ($link) {
            foreach (['visit', 'form_start', 'form_submit'] as $type) {
                TrackingEvent::create(['tracking_link_id' => $link->id, 'event_type' => $type, 'occurred_at' => now(), 'visitor_id' => 'v_aaaaaaaaaaaaaaaaaaaa']);
            }
        });

        $row = $this->row('landing_page');

        $this->assertTrue($row['connected']);
        $this->assertTrue($row['receiving']);
        $this->assertSame(3, $row['events']);
    }

    public function test_sales_the_platform_has_not_been_told_about_are_the_loudest_warning(): void
    {
        $this->connect('meta');
        $this->arrival('meta');
        $won = $this->lead('meta', ['status' => 'won', 'won_at' => now()->subHours(3), 'campaign_name' => 'حملة', 'utm_source' => 'meta']);
        $this->asTenant($this->t['tenant'], fn () => Lead::query()->update(['campaign_name' => 'حملة', 'utm_source' => 'meta']));

        $row = $this->row('meta');
        $this->assertSame(1, $row['offline']['won_waiting']);
        $this->assertSame('risk', $row['status']);
        $first = $this->health()['recommendations'][0];
        $this->assertSame('won_not_sent', $first['key']);
        $this->assertSame('critical', $first['severity']);
        $this->assertSame('meta', $first['platform']);

        // Once a Won reaches the platform, the warning goes.
        $this->asTenant($this->t['tenant'], fn () => ConversionExport::create(['lead_id' => $won->id, 'platform' => 'meta', 'event' => 'won', 'occurred_at' => now(), 'value' => 1000, 'currency' => 'SAR', 'status' => 'sent', 'sent_at' => now()]));

        $this->assertNotContains('won_not_sent', array_column($this->health()['recommendations'], 'key'));
        $this->assertSame('healthy', $this->row('meta')['status']);
        $this->assertNotNull($this->row('meta')['offline']['won_last_sent_at']);
    }

    public function test_links_without_utm_are_called_out_with_their_share(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            TrackingLink::create(['token' => 'tokone1234', 'name' => 'أ', 'channel' => 'meta', 'utm_source' => 'meta']);
            foreach (['tokone2345', 'tokone3456', 'tokone4567'] as $token) {
                TrackingLink::create(['token' => $token, 'name' => 'ب', 'channel' => 'meta']);
            }
        });

        $data = $this->health();

        $this->assertEquals(75.0, $data['summary']['links_without_utm_percent']);
        $rec = collect($data['recommendations'])->firstWhere('key', 'links_without_utm');
        $this->assertEquals(75.0, $rec['params']['percent']);
        $this->assertSame('links', $rec['to']);
    }

    public function test_the_worst_things_come_first_and_there_are_never_more_than_six(): void
    {
        foreach (['meta', 'tiktok', 'snapchat', 'google_ads'] as $p) {
            $this->connect($p);
            $this->arrival($p, at: now()->subDays(5));
        }
        $this->lead('meta');

        $recs = $this->health()['recommendations'];

        $this->assertLessThanOrEqual(6, count($recs));
        $this->assertSame('critical', $recs[0]['severity']);
        $severities = array_column($recs, 'severity');
        $this->assertSame($severities, collect($severities)->sortBy(fn ($s) => $s === 'critical' ? 0 : 1)->values()->all());
    }

    public function test_the_window_can_be_chosen_and_an_odd_one_falls_back(): void
    {
        $this->assertSame(30, $this->health(['days' => 30])['days']);
        $this->assertSame(7, $this->health(['days' => 3])['days']);
        $this->assertCount(30, $this->health(['days' => 30])['platforms'][0]['trend']);
    }

    public function test_only_managers_see_it_and_only_their_own_workspace(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $this->actingAs($rep)->getJson('/api/v1/tracking/health')->assertForbidden();

        $this->connect('meta');
        $this->arrival('meta');
        $other = $this->provisionTenant('Rival Co');

        $this->assertSame(0, collect($this->actingAs($other['admin'])->getJson('/api/v1/tracking/health')->json('data.platforms'))->firstWhere('platform', 'meta')['events']);
    }

    public function test_it_costs_a_fixed_number_of_queries_however_many_leads_there_are(): void
    {
        $this->connect('meta');
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->health();

            return count(DB::getQueryLog());
        };

        $this->arrival('meta');
        $few = $count();
        foreach (range(1, 12) as $i) {
            $this->arrival('meta');
            $this->lead('tiktok');
        }

        $this->assertSame($few, $count());
    }
}
