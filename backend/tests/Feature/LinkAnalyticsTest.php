<?php

namespace Tests\Feature;

use App\Domain\Access\Permissions;
use App\Domain\Tracking\LinkAnalytics;
use App\Enums\Role;
use App\Models\DestinationDelivery;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Reservation;
use App\Models\TrackingDestination;
use App\Models\TrackingEvent;
use App\Models\TrackingLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Everything one link did, from the first click to the riyal: the counts, the rates between them, the funnel with where
 * people dropped out, and whether the tracking itself can be trusted. Every figure is the one the rest of the product
 * uses (the funnel is the lead funnel; the leads are the first-touch leads) — a number cannot mean two things.
 */
class LinkAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private array $rival;

    private Project $project;

    private TrackingLink $link;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Analytics Co');
        $this->rival = $this->provisionTenant('Rival Analytics Co');
        $this->project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
        $this->link = $this->asTenant($this->t['tenant'], fn () => TrackingLink::create([
            'token' => 'ana'.random_int(1000000, 9999999), 'name' => 'إعلان ميتا', 'channel' => 'meta', 'mode' => 'landing', 'project_id' => $this->project->id,
            'utm_source' => 'meta', 'utm_medium' => 'paid_social', 'utm_campaign' => 'oct',
        ]));
    }

    private function event(string $type, string $visitor, array $extra = []): void
    {
        $this->asTenant($this->t['tenant'], fn () => TrackingEvent::create($extra + [
            'tracking_link_id' => $this->link->id, 'event_type' => $type, 'visitor_id' => 'v_'.str_pad($visitor, 20, '0'), 'occurred_at' => now()->subDay(), 'project_id' => $this->project->id,
            'event_id' => (string) str()->uuid(), 'session_id' => 's_'.str_pad($visitor, 20, '0'),
        ]));
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], function () use ($attributes) {
            $lead = Lead::create(['full_name' => 'عميل', 'phone_normalized' => '+9665512'.random_int(10000, 99999), 'status' => 'new', 'project_id' => $this->project->id]);
            // A lead past "new" has been contacted: the database insists on a time for it.
            if (($attributes['status'] ?? 'new') !== 'new') {
                $attributes += ['first_contact_at' => now()->subDay(), 'last_contact_at' => now()->subDay()];
            }
            $lead->forceFill($attributes + ['first_touch_link_id' => $this->link->id, 'last_touch_link_id' => $this->link->id, 'created_at' => now()->subDay()])->saveQuietly();

            return $lead->fresh();
        });
    }

    /** 10 clicks from 6 visitors, 8 views, 5 form starts, 3 submits; 3 leads — 2 qualified, 1 interested, 1 visited, 1 reserved, 1 won for 900,000. */
    private function aJourney(): void
    {
        foreach (range(1, 10) as $i) {
            $this->event('click', (string) (($i % 6) + 1));
        }
        foreach (range(1, 8) as $i) {
            $this->event('visit', (string) $i);
        }
        foreach (range(1, 5) as $i) {
            $this->event('form_start', (string) $i);
        }
        foreach (range(1, 3) as $i) {
            $this->event('form_submit', (string) $i);
        }
        $this->event('whatsapp_click', '1');
        $this->event('whatsapp_click', '2');
        $this->event('call_click', '3');

        $first = $this->lead(['qualification_status' => 'qualified', 'status' => 'qualified']);
        $second = $this->lead(['qualification_status' => 'qualified', 'status' => 'qualified', 'interest_level' => 'hot', 'first_contact_at' => now()->subDay(), 'last_contact_at' => now()->subDay()]);
        $third = $this->lead(['status' => 'won', 'won_at' => now(), 'deal_value_minor' => 90000000, 'first_contact_at' => now()->subDay(), 'last_contact_at' => now()->subDay()]);

        $this->asTenant($this->t['tenant'], function () use ($second, $third) {
            DB::table('appointments')->insert(['uuid' => (string) str()->uuid(), 'tenant_id' => $this->t['tenant']->id, 'lead_id' => $second->id, 'scheduled_at' => now()->subHours(5), 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
            Reservation::create(['lead_id' => $third->id, 'status' => 'converted']);
            DB::table('appointments')->insert(['uuid' => (string) str()->uuid(), 'tenant_id' => $this->t['tenant']->id, 'lead_id' => $third->id, 'scheduled_at' => now()->subDays(2), 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    private function analytics(array $query = []): array
    {
        return $this->actingAs($this->t['admin'])->getJson("/api/v1/tracking/links/{$this->link->uuid}/analytics?".http_build_query($query + ['days' => 30]))->assertOk()->json('data');
    }

    public function test_the_counts_are_what_the_trail_recorded_and_the_leads_are_the_ones_it_brought(): void
    {
        $this->aJourney();

        $c = $this->analytics()['analytics']['counts'];

        $this->assertSame(10, $c['clicks']);
        $this->assertSame(8, $c['unique_visitors'], 'eight different people did something on this link');
        $this->assertSame(8, $c['landing_views']);
        $this->assertSame(5, $c['form_starts']);
        $this->assertSame(3, $c['form_submissions']);
        $this->assertSame(3, $c['leads']);
        $this->assertSame(3, $c['qualified'], 'a won lead was, by definition, qualified');
        $this->assertSame(2, $c['interested'], 'the one who visited and the one who won');
        $this->assertSame(2, $c['whatsapp']);
        $this->assertSame(1, $c['calls']);
        $this->assertSame(2, $c['appointments'], 'two leads have a visit booked');
        $this->assertSame(1, $c['visits']);
        $this->assertSame(1, $c['reservations']);
        $this->assertSame(1, $c['won']);
        $this->assertEquals(900000.0, (float) $c['revenue']);
    }

    public function test_the_rates_are_each_step_over_the_one_it_comes_from(): void
    {
        $this->aJourney();

        $r = $this->analytics()['analytics']['rates'];

        $this->assertEquals(80.0, $r['view_rate'], '8 views of 10 clicks');
        $this->assertEquals(62.5, $r['form_start_rate'], '5 starts of 8 views');
        $this->assertEquals(60.0, $r['completion_rate'], '3 submissions of 5 starts');
        $this->assertEquals(30.0, $r['lead_rate'], '3 leads of 10 clicks');
        $this->assertEquals(100.0, $r['qualification_rate'], '3 of 3 leads');
        $this->assertEquals(66.7, $r['interested_rate']);
        $this->assertEquals(66.7, $r['appointment_rate']);
        $this->assertEquals(33.3, $r['reservation_rate']);
        $this->assertEquals(33.3, $r['close_rate']);
        $this->assertEquals(300000.0, (float) $r['revenue_per_lead']);
        $this->assertEquals(90000.0, (float) $r['revenue_per_click']);
    }

    public function test_the_funnel_runs_click_to_won_and_says_where_people_were_lost(): void
    {
        $this->aJourney();

        $funnel = collect($this->analytics()['analytics']['funnel']);

        $this->assertSame(['click', 'view', 'form_start', 'submit', 'qualified', 'interested', 'visit', 'reservation', 'won'], $funnel->pluck('key')->all());
        $this->assertSame([10, 8, 5, 3, 3, 2, 1, 1, 1], $funnel->pluck('count')->all());
        $this->assertEquals(20.0, $funnel->firstWhere('key', 'view')['drop_off'], '2 of 10 never saw the page');
        $this->assertEquals(37.5, $funnel->firstWhere('key', 'form_start')['drop_off']);
        $this->assertEquals(0.0, $funnel->firstWhere('key', 'qualified')['drop_off']);
        $this->assertEquals(10.0, $funnel->firstWhere('key', 'won')['of_start']);
    }

    public function test_money_is_for_those_who_may_see_it(): void
    {
        $this->aJourney();
        $manager = $this->makeUser($this->t['tenant'], Role::MarketingManager);

        $c = $this->actingAs($manager)->getJson("/api/v1/tracking/links/{$this->link->uuid}/analytics")->assertOk()->json('data.analytics');

        $this->assertArrayHasKey('counts', $c);
        // A role without the revenue permission gets the funnel and not the figures.
        if (! Permissions::has($manager, 'revenue.view')) {
            $this->assertNull($c['counts']['revenue']);
            $this->assertNull($c['rates']['revenue_per_lead']);
        } else {
            $this->assertNotNull($c['counts']['revenue']);
        }
    }

    // ---------------------------------------------------------------- whose link

    public function test_a_link_of_another_workspace_has_no_analytics_here(): void
    {
        $theirs = $this->asTenant($this->rival['tenant'], fn () => TrackingLink::create(['token' => 'rival'.random_int(100000, 999999), 'name' => 'رابطهم', 'channel' => 'meta']));

        $this->actingAs($this->t['admin'])->getJson("/api/v1/tracking/links/{$theirs->uuid}/analytics")->assertNotFound();
    }

    public function test_a_salesperson_does_not_reach_a_links_analytics(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->actingAs($rep)->getJson("/api/v1/tracking/links/{$this->link->uuid}/analytics")->assertForbidden();
    }

    public function test_the_analytics_of_one_workspace_never_count_anothers_events(): void
    {
        $this->aJourney();
        $this->asTenant($this->rival['tenant'], fn () => TrackingEvent::create(['tracking_link_id' => $this->link->id, 'event_type' => 'click', 'visitor_id' => 'v_'.str_repeat('z', 20), 'occurred_at' => now()]));

        $this->assertSame(10, $this->analytics()['analytics']['counts']['clicks'], 'an event of another workspace was counted');
    }

    // ---------------------------------------------------------------- can it be trusted

    public function test_a_link_nobody_has_clicked_says_so_and_a_link_with_traffic_is_judged_on_it(): void
    {
        $health = $this->analytics()['health'];

        $this->assertSame('warning', $health['status']);
        $this->assertSame('warning', collect($health['checks'])->firstWhere('key', 'last_event')['state']);
    }

    public function test_a_paid_link_with_clicks_but_no_click_id_and_no_utm_is_flagged(): void
    {
        $this->asTenant($this->t['tenant'], fn () => $this->link->update(['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null]));
        foreach (range(1, 25) as $i) {
            $this->event('click', (string) $i);
        }

        $checks = collect($this->analytics()['health']['checks'])->keyBy('key');

        $this->assertSame('warning', $checks['utm']['state']);
        $this->assertEqualsCanonicalizing(['utm_source', 'utm_medium', 'utm_campaign'], $checks['utm']['missing']);
        $this->assertSame('critical', $checks['click_id']['state'], 'twenty-five Meta clicks and not one fbclid: Meta cannot credit anything');
        $this->assertSame('critical', $this->analytics()['health']['status']);
    }

    public function test_a_link_whose_destinations_are_failing_is_not_healthy(): void
    {
        $this->aJourney();
        $destination = $this->asTenant($this->t['tenant'], fn () => TrackingDestination::create(['kind' => 'webhook', 'name' => 'نظامنا', 'is_enabled' => true, 'config' => ['endpoint_url' => 'https://hooks.example.test/in'], 'credentials' => ['secret' => 's'], 'send_value' => true, 'hash_identifiers' => true, 'consent_mode' => 'required']));
        $this->asTenant($this->t['tenant'], function () use ($destination) {
            foreach (range(1, 6) as $i) {
                DestinationDelivery::create(['destination_id' => $destination->id, 'event_id' => (string) str()->uuid(), 'event_name' => 'qualified', 'status' => 'failed', 'attempts' => 4, 'last_error' => 'HTTP 500']);
            }
        });

        $server = collect($this->analytics()['health']['checks'])->firstWhere('key', 'server_events');

        $this->assertSame('critical', $server['state']);
        $this->assertSame(['نظامنا'], $server['failing']);
    }

    public function test_the_analytics_class_is_the_one_the_page_uses(): void
    {
        $this->aJourney();

        $direct = $this->asTenant($this->t['tenant'], fn () => app(LinkAnalytics::class)->forLinks([$this->link->id], now()->subDays(30), now(), true));

        $this->assertSame($this->analytics()['analytics']['counts']['leads'], $direct['counts']['leads']);
    }
}
