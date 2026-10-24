<?php

namespace Tests\Feature;

use App\Domain\Funnel\FunnelFilters;
use App\Domain\Funnel\RealEstateFunnel;
use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\MetricSnapshot;
use App\Models\Partner;
use App\Models\Project;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\TrackingEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The one funnel: وعي → اهتمام → تفاعل → حجز معاينة → قرار شراء → إحالة, drawn from what Mohtam really recorded.
 */
class CanonicalFunnelTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Project $p1;

    private Project $p2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Funnel Co');
        $this->asTenant($this->t['tenant'], function () {
            $this->p1 = Project::create(['name' => 'Tower One']);
            $this->p2 = Project::create(['name' => 'Tower Two']);
        });
    }

    private function lead(Project $project, string $status = 'new', array $extra = []): Lead
    {
        // forceFill: the revenue and partner columns are the system's to write, not a form's.
        return $this->asTenant($this->t['tenant'], function () use ($project, $status, $extra) {
            $lead = new Lead;
            $lead->forceFill(array_merge([
                'full_name' => 'عميل '.Str::random(4), 'phone' => '05'.random_int(10000000, 99999999),
                'phone_normalized' => '+9665'.random_int(10000000, 99999999), 'status' => $status, 'project_id' => $project->id, 'platform' => 'meta',
            ], $extra))->save();

            return $lead;
        });
    }

    private function funnel(array $projects = [], array $more = []): array
    {
        return $this->asTenant($this->t['tenant'], fn () => (new RealEstateFunnel)->build(
            new FunnelFilters(now()->subDays(29), now(), array_map(fn ($p) => $p->id, $projects), ...$more)
        ));
    }

    private function stage(array $funnel, string $key): array
    {
        return collect($funnel['stages'])->firstWhere('key', $key);
    }

    /** One project with a lead at every point of the journey. */
    private function journey(Project $p): void
    {
        $this->lead($p);                                              // interest only
        $this->lead($p, 'contacted');                                 // engaged
        $this->lead($p, 'qualified');                                 // engaged
        $booked = $this->lead($p, 'appointment');
        $this->asTenant($this->t['tenant'], fn () => Appointment::create(['lead_id' => $booked->id, 'project_id' => $p->id, 'scheduled_at' => now()->addDay(), 'status' => 'confirmed']));
        $held = $this->lead($p, 'negotiation');
        $unit = $this->asTenant($this->t['tenant'], fn () => Property::create(['project_id' => $p->id, 'code' => 'U-'.Str::random(3), 'title' => 'Unit', 'property_type' => 'apartment', 'status' => 'available']));
        $this->asTenant($this->t['tenant'], fn () => Reservation::create(['lead_id' => $held->id, 'project_id' => $p->id, 'property_id' => $unit->id, 'status' => 'pending', 'amount_minor' => 1000, 'reserved_at' => now()]));
        $this->lead($p, 'won', ['deal_value_minor' => 90000000, 'won_at' => now()]);
        $partner = $this->asTenant($this->t['tenant'], fn () => Partner::create(['name' => 'Broker', 'type' => 'broker', 'is_active' => true]));
        $this->lead($p, 'new', ['partner_id' => $partner->id]);       // arrived through a broker
    }

    public function test_the_six_stages_are_the_official_ones_in_order(): void
    {
        $this->assertSame(['awareness', 'interest', 'engagement', 'booking', 'decision', 'referral'], RealEstateFunnel::STAGES);
        $this->assertSame(['وعي', 'اهتمام', 'تفاعل', 'حجز معاينة', 'قرار شراء', 'إحالة'], array_values(RealEstateFunnel::LABELS));
    }

    public function test_each_stage_is_counted_from_real_records_and_contains_the_ones_that_went_further(): void
    {
        $this->journey($this->p1);
        $f = $this->funnel();

        $this->assertSame(7, $this->stage($f, 'interest')['count']);
        // contacted, qualified, appointment, negotiation and won reached the team; the untouched ones did not.
        $this->assertSame(5, $this->stage($f, 'engagement')['count']);
        $this->assertSame(3, $this->stage($f, 'booking')['count']);      // appointment + negotiation (reserved) + won
        $this->assertSame(2, $this->stage($f, 'decision')['count']);     // negotiation (reserved) + won
        $this->assertSame(1, $this->stage($f, 'referral')['count']);
        $this->assertSame(900000.0, $this->stage($f, 'decision')['revenue']);
        $this->assertSame(900000.0, $f['revenue']);
    }

    public function test_conversion_and_drop_off_are_from_the_stage_before(): void
    {
        $this->journey($this->p1);
        $f = $this->funnel();

        $this->assertSame(round(5 / 7 * 100, 1), $this->stage($f, 'engagement')['rate']);
        $this->assertSame(round(100 - 5 / 7 * 100, 1), $this->stage($f, 'engagement')['drop_off']);
        $this->assertSame(round(3 / 5 * 100, 1), $this->stage($f, 'booking')['rate']);
        $this->assertSame(round(2 / 3 * 100, 1), $this->stage($f, 'decision')['rate']);

        // A referred lead enters at interest: referral is not a subset of the decision, so it has no conversion.
        $referral = $this->stage($f, 'referral');
        $this->assertNull($referral['rate']);
        $this->assertNull($referral['drop_off']);
        $this->assertFalse($referral['subset_of_previous']);
    }

    public function test_the_operational_stages_remain_as_sub_stages(): void
    {
        $this->journey($this->p1);
        $f = $this->funnel();

        $this->assertSame(['contacted', 'qualified', 'interested'], array_column($this->stage($f, 'engagement')['sub_stages'], 'key'));
        $this->assertSame(['appointment_booked', 'visit_completed'], array_column($this->stage($f, 'booking')['sub_stages'], 'key'));
        $this->assertSame(['reservation', 'negotiation', 'won'], array_column($this->stage($f, 'decision')['sub_stages'], 'key'));
        $this->assertSame(1, collect($this->stage($f, 'decision')['sub_stages'])->firstWhere('key', 'negotiation')['count']);
    }

    public function test_the_previous_period_is_compared_and_a_missing_one_is_not_a_hundred_percent_jump(): void
    {
        $lead = $this->lead($this->p1);
        $this->asTenant($this->t['tenant'], fn () => Lead::withoutGlobalScopes()->whereKey($lead->id)->update(['created_at' => now()->subDays(40)]));
        $this->lead($this->p1);
        $this->lead($this->p1);

        $f = $this->funnel();
        $interest = $this->stage($f, 'interest');

        $this->assertSame(2, $interest['count']);
        $this->assertSame(1, $interest['previous']);
        $this->assertSame(100.0, $interest['change']);

        // Nothing came in the period before: no change figure at all, rather than an infinite or invented one.
        $this->assertNull($this->stage($f, 'decision')['change']);
    }

    public function test_awareness_is_unavailable_not_zero_when_nothing_measured_traffic(): void
    {
        $this->lead($this->p1);
        $awareness = $this->stage($this->funnel(), 'awareness');

        $this->assertFalse($awareness['available']);
        $this->assertNull($awareness['count']);
        $this->assertSame('no_traffic_data', $awareness['reason']);
        $this->assertNull($awareness['basis']);
    }

    public function test_awareness_from_the_platform_and_from_mohtam_are_never_added_together(): void
    {
        $this->lead($this->p1);
        $this->asTenant($this->t['tenant'], function () {
            $campaign = Campaign::create(['project_id' => $this->p1->id, 'platform' => 'meta', 'name' => 'C', 'origin' => 'manual', 'status' => 'active']);
            MetricSnapshot::create(['project_id' => $this->p1->id, 'platform' => 'meta', 'level' => 'campaign', 'campaign_id' => $campaign->id, 'date' => now()->toDateString(), 'spend_minor' => 5000, 'impressions' => 12000, 'clicks' => 300]);

            foreach (range(1, 40) as $i) {
                TrackingEvent::create(['event_type' => 'click', 'occurred_at' => now(), 'visitor_id' => 'v'.$i, 'project_id' => $this->p1->id, 'dedupe_key' => 'd'.$i]);
            }
        });

        $awareness = $this->stage($this->funnel(), 'awareness');

        $this->assertSame('platform', $awareness['basis']);
        $this->assertSame(12000, $awareness['count']);   // the platform's impressions — not 12000 + 40
        $this->assertSame(12000, $awareness['components']['platform_reported']['impressions']);
        $this->assertSame(40, $awareness['components']['mohtam_measured']['visitors']);
    }

    public function test_without_a_connected_platform_awareness_is_what_mohtam_measured(): void
    {
        $this->lead($this->p1);
        $this->asTenant($this->t['tenant'], function () {
            foreach (range(1, 25) as $i) {
                TrackingEvent::create(['event_type' => 'visit', 'occurred_at' => now(), 'visitor_id' => 'w'.$i, 'project_id' => $this->p1->id, 'dedupe_key' => 'w'.$i]);
            }
        });

        $awareness = $this->stage($this->funnel(), 'awareness');

        $this->assertSame('mohtam', $awareness['basis']);
        $this->assertSame(25, $awareness['count']);
        $this->assertNull($awareness['components']['platform_reported']);
    }

    public function test_filters_narrow_every_stage(): void
    {
        $this->journey($this->p1);
        $this->lead($this->p2);
        $this->lead($this->p2, 'contacted');

        $this->assertSame(7, $this->stage($this->funnel([$this->p1]), 'interest')['count']);
        $this->assertSame(2, $this->stage($this->funnel([$this->p2]), 'interest')['count']);
        $this->assertSame(9, $this->stage($this->funnel([$this->p1, $this->p2]), 'interest')['count']);
        $this->assertSame(9, $this->stage($this->funnel(), 'interest')['count']);
        $this->assertSame(0, $this->stage($this->funnel([$this->p2]), 'decision')['count']);
    }

    public function test_company_a_funnel_never_counts_company_b(): void
    {
        $this->journey($this->p1);
        $b = $this->provisionTenant('Beta Funnel');
        $this->asTenant($b['tenant'], function () {
            $p = Project::create(['name' => 'Beta Tower']);
            foreach (range(1, 12) as $i) {
                Lead::create(['full_name' => 'بيتا '.$i, 'phone' => '0500000'.$i, 'phone_normalized' => '+96650000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'status' => 'won', 'project_id' => $p->id, 'deal_value_minor' => 100]);
            }
        });

        $f = $this->funnel();

        $this->assertSame(7, $this->stage($f, 'interest')['count']);
        $this->assertSame(900000.0, $f['revenue']);
    }

    public function test_the_trend_has_one_point_per_day_and_adds_up_to_the_stage(): void
    {
        $this->journey($this->p1);
        $f = $this->funnel();

        $this->assertSame('day', $f['trend']['bucket']);
        $this->assertCount(30, $f['trend']['points']);
        $this->assertSame(7, array_sum(array_column($f['trend']['points'], 'interest')));
        $this->assertSame(5, array_sum(array_column($f['trend']['points'], 'engagement')));
        $this->assertSame(2, array_sum(array_column($f['trend']['points'], 'decision')));
    }

    public function test_revenue_is_left_out_when_the_asker_may_not_see_it(): void
    {
        $this->journey($this->p1);
        $f = $this->asTenant($this->t['tenant'], fn () => (new RealEstateFunnel)->build(new FunnelFilters(now()->subDays(29), now()), null, false));

        $this->assertNull($f['revenue']);
        $this->assertNull($this->stage($f, 'decision')['revenue']);
        $this->assertArrayNotHasKey('revenue', collect($this->stage($f, 'decision')['sub_stages'])->firstWhere('key', 'won'));
    }
}
