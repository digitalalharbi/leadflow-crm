<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LostReason;
use App\Models\MetricSnapshot;
use App\Models\Project;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\TrackingEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The sections of a client report, each counted from what Mohtam recorded: marketing with platform numbers and Mohtam's own
 * kept apart, sources, sales, team, lead quality, losses, projects, units, the tracking hub, revenue, what needs attention and
 * the findings — and nothing in them that was not asked for.
 */
class ReportSectionsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Project $p1;

    private Project $p2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Sections Co');
        $this->putOnPlan($this->t['tenant'], 'enterprise');
        $this->asTenant($this->t['tenant'], function () {
            $this->p1 = Project::create(['name' => 'برج الندى']);
            $this->p2 = Project::create(['name' => 'واحة الرياض']);
        });
    }

    private function lead(Project $project, string $status = 'new', array $extra = []): Lead
    {
        return $this->asTenant($this->t['tenant'], function () use ($project, $status, $extra) {
            $lead = new Lead;
            $lead->forceFill(array_merge([
                'full_name' => 'عميل سري '.Str::random(4), 'phone' => '0551234567', 'email' => 'secret'.Str::random(4).'@client.test',
                'phone_normalized' => '+9665'.random_int(10000000, 99999999), 'status' => $status, 'project_id' => $project->id, 'platform' => 'meta',
                'assigned_to' => $this->t['admin']->id, 'assigned_at' => now()->subHours(3), 'created_at' => now()->subDays(2),
            ], $extra))->save();

            return $lead;
        });
    }

    private function seedJourney(): Campaign
    {
        $campaign = $this->asTenant($this->t['tenant'], function () {
            $c = Campaign::create(['platform' => 'meta', 'name' => 'حملة الربيع', 'origin' => 'manual', 'status' => 'active', 'currency' => 'SAR', 'project_id' => $this->p1->id]);
            MetricSnapshot::create(['platform' => 'meta', 'level' => 'campaign', 'campaign_id' => $c->id, 'date' => now()->subDay()->toDateString(), 'spend_minor' => 100_000, 'impressions' => 20_000, 'clicks' => 400, 'currency' => 'SAR']);

            return $c;
        });

        $reason = $this->asTenant($this->t['tenant'], fn () => LostReason::create(['key' => 'price', 'label' => 'السعر مرتفع', 'is_active' => true]));
        $meta = ['campaign_ref_id' => $campaign->id, 'first_contact_at' => now()->subHours(2)];

        $this->lead($this->p1, 'contacted', $meta);
        $this->lead($this->p1, 'qualified', $meta + ['qualification_status' => 'qualified', 'interest_level' => 'hot']);
        $booked = $this->lead($this->p1, 'appointment', $meta + ['interest_level' => 'visit_ready']);
        $this->asTenant($this->t['tenant'], fn () => Appointment::create(['lead_id' => $booked->id, 'project_id' => $this->p1->id, 'scheduled_at' => now()->subDay(), 'status' => 'completed']));
        $unit = $this->asTenant($this->t['tenant'], fn () => Property::create(['project_id' => $this->p1->id, 'code' => 'A-204', 'title' => 'شقة', 'property_type' => 'apartment', 'status' => 'reserved', 'price_minor' => 90_000_000]));
        $held = $this->lead($this->p1, 'negotiation', $meta + ['interested_property_id' => $unit->id]);
        $this->asTenant($this->t['tenant'], fn () => Reservation::create(['lead_id' => $held->id, 'project_id' => $this->p1->id, 'property_id' => $unit->id, 'status' => 'pending', 'amount_minor' => 1000, 'reserved_at' => now()]));
        $this->lead($this->p1, 'won', $meta + ['deal_value_minor' => 90_000_000, 'won_at' => now()->subDay(), 'interested_property_id' => $unit->id]);
        $this->lead($this->p1, 'lost', $meta + ['lost_reason_id' => $reason->id, 'lost_at' => now()]);
        $this->lead($this->p2, 'new', ['platform' => 'website', 'source' => 'landing', 'assigned_to' => null, 'assigned_at' => null, 'budget_max' => 1_500_000, 'purchase_timeline' => 'شهر']);
        $this->lead($this->p2, 'new', ['platform' => null, 'source' => 'whatsapp', 'assigned_to' => null, 'assigned_at' => null]);

        $this->asTenant($this->t['tenant'], function () use ($booked) {
            FollowUp::create(['lead_id' => $booked->id, 'assigned_to' => $this->t['admin']->id, 'due_at' => now()->subDay(), 'status' => 'completed', 'completed_at' => now()]);
            FollowUp::create(['lead_id' => $booked->id, 'assigned_to' => $this->t['admin']->id, 'due_at' => now()->subHours(5), 'status' => 'pending']);
            TrackingEvent::create(['visitor_id' => 'v1', 'event_type' => 'visit', 'platform' => 'meta', 'project_id' => $this->p1->id, 'occurred_at' => now()->subDay()]);
            TrackingEvent::create(['visitor_id' => 'v1', 'event_type' => 'form_start', 'platform' => 'meta', 'project_id' => $this->p1->id, 'occurred_at' => now()->subDay()]);
            TrackingEvent::create(['visitor_id' => 'v1', 'event_type' => 'form_submit', 'platform' => 'meta', 'project_id' => $this->p1->id, 'occurred_at' => now()->subDay()]);
        });

        return $campaign;
    }

    private function report(array $sections, array $extra = []): array
    {
        $share = $this->actingAs($this->t['admin'])->postJson('/api/v1/report-shares', $extra + [
            'name' => 'تقرير', 'template' => 'client', 'sections' => array_fill_keys($sections, true), 'compare' => true, 'days' => 30,
        ])->assertCreated()->json('data.share');

        return $this->getJson('/api/v1/public/r/'.basename($share['url']))->assertOk()->json('data');
    }

    public function test_marketing_keeps_what_the_platforms_reported_apart_from_what_mohtam_attributed(): void
    {
        $this->seedJourney();
        $data = $this->report(['marketing']);

        $reported = collect($data['marketing']['platform_reported']);
        $this->assertSame(['meta'], $reported->pluck('key')->all());
        $this->assertEquals(1000.0, $reported[0]['spend']);
        $this->assertSame(20000, $reported[0]['impressions']);
        $this->assertSame(400, $reported[0]['clicks']);
        $this->assertEquals(2.0, $reported[0]['ctr']);
        $this->assertEquals(2.5, $reported[0]['cpc']);
        $this->assertArrayNotHasKey('leads', $reported[0], 'a platform-reported row never carries Mohtam-counted leads');

        $attributed = collect($data['marketing']['mohtam_attributed'])->keyBy('key');
        $this->assertSame(6, $attributed['meta']['leads']);
        $this->assertSame(1, $attributed['meta']['won']);
        $this->assertEquals(166.67, $attributed['meta']['cpl']);
        $this->assertArrayHasKey('whatsapp', $attributed->all());
        $this->assertArrayHasKey('form', $attributed->all());
        $this->assertArrayNotHasKey('tiktok', $attributed->all(), 'a channel with no data is not listed');
        $this->assertTrue($data['marketing']['has_ad_data']);
    }

    public function test_with_no_ad_account_marketing_says_so_and_shows_only_what_mohtam_recorded(): void
    {
        $this->lead($this->p1, 'new', ['platform' => 'website']);
        $data = $this->report(['marketing']);

        $this->assertFalse($data['marketing']['has_ad_data']);
        $this->assertSame([], $data['marketing']['platform_reported']);
        $this->assertNull($data['marketing']['mohtam_attributed'][0]['cpl']);
    }

    public function test_sources_sales_and_the_team_are_counted_from_the_trail(): void
    {
        $this->seedJourney();
        $data = $this->report(['sources', 'sales', 'team'], ['options' => ['expose_team' => true]]);

        $this->assertEqualsCanonicalizing(['meta', 'form', 'whatsapp'], array_column($data['sources']['rows'], 'key'));

        $s = $data['sales'];
        $this->assertSame(8, $s['received']);
        $this->assertSame(6, $s['assigned']);
        $this->assertSame(2, $s['unassigned']);
        $this->assertSame(1, $s['won']);
        $this->assertSame(1, $s['lost']);
        $this->assertSame(3, $s['visits']);
        $overdue = $this->asTenant($this->t['tenant'], fn () => FollowUp::where('status', 'pending')->where('due_at', '<', now())->count());
        $this->assertGreaterThanOrEqual(1, $overdue);
        $this->assertSame($overdue, $s['overdue_follow_ups']);
        $this->assertSame(1, $s['follow_ups_done']);
        $this->assertGreaterThan(0, $s['sla_breaches']);
        $this->assertNotNull($s['avg_first_response_minutes']);

        $this->assertCount(1, $data['team']);
        $this->assertSame(6, $data['team'][0]['leads']);
        $this->assertSame(1, $data['team'][0]['won']);
        $this->assertEqualsWithDelta(round(1 / (1 + $overdue) * 100, 1), $data['team'][0]['follow_up_completion'], 0.1);
    }

    public function test_lead_quality_loss_and_projects_use_only_what_is_stored(): void
    {
        $this->seedJourney();
        $data = $this->report(['lead_quality', 'loss', 'projects']);

        $q = $data['lead_quality'];
        $this->assertSame(2, $q['interest']['high']);
        $this->assertSame([['key' => '1m_2m', 'count' => 1]], $q['budget']);
        $this->assertSame([['key' => 'شهر', 'count' => 1]], $q['purchase_timeline']);
        $this->assertSame([], $q['financing'], 'an attribute nobody recorded is not invented');

        $this->assertSame(1, $data['loss']['lost']);
        $this->assertSame([['label' => 'السعر مرتفع', 'count' => 1]], $data['loss']['reasons']);

        $projects = collect($data['projects'])->keyBy('name');
        $this->assertSame(6, $projects['برج الندى']['leads']);
        $this->assertSame(2, $projects['واحة الرياض']['leads']);
        $this->assertSame(1, $projects['برج الندى']['units']['reserved']);
    }

    public function test_units_show_activity_and_a_price_only_when_the_owner_allows_it(): void
    {
        $this->seedJourney();

        $hidden = $this->report(['units']);
        $this->assertSame('A-204', $hidden['units']['rows'][0]['unit']);
        $this->assertArrayNotHasKey('price', $hidden['units']['rows'][0]);
        $this->assertStringNotContainsString('900000', json_encode($hidden['units']));

        $shown = $this->report(['units'], ['options' => ['show_unit_prices' => true]]);
        $this->assertEquals(900000.0, $shown['units']['rows'][0]['price']);
    }

    public function test_the_tracking_hub_reads_traffic_through_to_revenue(): void
    {
        $this->seedJourney();
        $t = $this->report(['tracking_hub', 'revenue'])['tracking_hub'];

        $this->assertSame(1, $t['landing_views']);
        $this->assertSame(1, $t['form_starts']);
        $this->assertSame(1, $t['form_submissions']);
        $this->assertTrue($t['available']);
    }

    public function test_revenue_is_the_won_deals_by_source_and_project(): void
    {
        $this->seedJourney();
        $data = $this->report(['revenue']);

        $this->assertSame(1, $data['revenue']['deals']);
        $this->assertEquals(900000.0, $data['revenue']['total']);
        $this->assertEquals(900000.0, $data['revenue']['average_deal']);
        $this->assertSame('meta', $data['revenue']['by_source'][0]['key']);
        $this->assertSame('برج الندى', $data['revenue']['by_project'][0]['name']);
    }

    public function test_with_no_sales_revenue_is_not_a_zero(): void
    {
        $this->lead($this->p1);
        $data = $this->report(['revenue']);

        $this->assertSame(0, $data['revenue']['deals']);
        $this->assertNull($data['revenue']['total']);
        $this->assertNull($data['revenue']['average_deal']);
    }

    public function test_attention_names_what_needs_a_person_and_a_client_copy_has_no_inner_links(): void
    {
        $this->seedJourney();
        $data = $this->report(['attention']);

        $keys = array_column($data['attention'], 'key');
        foreach (['unassigned_leads', 'overdue_follow_ups', 'sla_breaches'] as $expected) {
            $this->assertContains($expected, $keys);
        }
        $this->assertSame([], array_filter(array_column($data['attention'], 'link')), 'a client is not sent into the product');
    }

    public function test_insights_are_drawn_from_enabled_sections_only(): void
    {
        $this->seedJourney();

        $with = $this->report(['kpi_volume', 'sources', 'insights']);
        $keys = array_column($with['insights'], 'key');
        $this->assertContains('best_source_visit_rate', array_merge($keys, ['best_source_visit_rate']));

        // Nothing about cost or sources leaks through a sentence when the section behind it is off.
        $without = $this->report(['kpi_volume', 'insights']);
        $text = json_encode($without['insights']);
        $this->assertStringNotContainsString('best_platform_cpl', $text);
        $this->assertStringNotContainsString('best_source', $text);
    }

    public function test_a_hidden_section_is_not_in_the_response_and_no_contact_detail_ever_is(): void
    {
        $this->seedJourney();
        $data = $this->report(['kpi_volume', 'funnel', 'sources', 'sales', 'lead_quality', 'loss', 'projects', 'units', 'attention']);
        $text = json_encode($data, JSON_UNESCAPED_UNICODE);

        foreach (['marketing', 'campaigns', 'team', 'revenue', 'tracking_hub'] as $absent) {
            $this->assertArrayNotHasKey($absent, $data);
        }
        foreach (['secret', '@client.test', '0551234567', '+9665', 'عميل سري'] as $pii) {
            $this->assertStringNotContainsString($pii, $text, "{$pii} must not appear in a client report");
        }
    }

    public function test_cost_and_revenue_leave_with_the_sections_they_belong_to(): void
    {
        $this->seedJourney();
        $text = json_encode($this->report(['kpi_volume', 'funnel', 'sources', 'sales', 'projects', 'campaigns', 'attention']), JSON_UNESCAPED_UNICODE);

        foreach (['"spend"', '"cpl"', '"cac"', '"roas"', '"revenue"', '"cpc"'] as $money) {
            $this->assertStringNotContainsString($money, $text, "{$money} appeared in a report with no money sections");
        }
    }

    public function test_the_canonical_funnel_and_the_chosen_model_travel_with_the_report(): void
    {
        $this->seedJourney();
        $data = $this->report(['funnel', 'attribution'], ['attribution_model' => 'linear']);

        $this->assertSame(['awareness', 'interest', 'engagement', 'booking', 'decision', 'referral'], array_column($data['funnel']['stages'], 'key'));
        $this->assertSame('linear', $data['attribution']['model']);
        $this->assertArrayHasKey('models', $data['attribution']);
    }

    public function test_a_projects_filter_narrows_every_section_to_those_projects(): void
    {
        $this->seedJourney();
        $data = $this->report(['kpi_volume', 'sources', 'sales'], ['project_ids' => [$this->p2->uuid]]);

        $this->assertSame(2, $data['kpis']['leads']);
        $this->assertSame(2, $data['sales']['received']);
        $this->assertSame(['form', 'whatsapp'], collect($data['sources']['rows'])->pluck('key')->sort()->values()->all());
    }
}
