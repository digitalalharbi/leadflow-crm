<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Enums\QualificationStatus;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The figures behind the dashboard's charts.
 *
 * These are the numbers a sales manager will quote in a meeting, so the tests
 * are about whether they are *right* rather than whether they are present.
 */
class DashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Analytics Co');
    }

    public function test_the_funnel_counts_leads_that_reached_a_stage_not_leads_sitting_in_it(): void
    {
        // A won lead passed through every stage. Counting only current status
        // would show a company that closes everything as having an empty
        // funnel, with every conversion reading zero.
        $this->lead('مكسوب', LeadStatus::Won, qualified: true, contacted: true);
        $this->lead('موعد', LeadStatus::Appointment, qualified: true, contacted: true);
        $this->lead('مؤهل', LeadStatus::Qualified, qualified: true, contacted: true);
        $this->lead('تم التواصل', LeadStatus::Contacted, contacted: true);
        $this->lead('جديد', LeadStatus::New);

        $funnel = collect($this->dashboard()['funnel'])->keyBy('key');

        $this->assertSame(5, $funnel['total']['count']);
        $this->assertSame(4, $funnel['contacted']['count']);
        $this->assertSame(3, $funnel['qualified']['count']);
        $this->assertSame(2, $funnel['appointment']['count']);
        $this->assertSame(1, $funnel['won']['count']);
    }

    public function test_the_funnel_reports_both_conversions(): void
    {
        foreach (range(1, 10) as $i) {
            $this->lead('عميل '.$i, $i <= 5 ? LeadStatus::Contacted : LeadStatus::New, contacted: $i <= 5);
        }

        $funnel = collect($this->dashboard()['funnel'])->keyBy('key');

        // Half of everything reached contact, and that is both the share of
        // the total and the step conversion at this stage.
        $this->assertEquals(50, $funnel['contacted']['of_total']);
        $this->assertEquals(50, $funnel['contacted']['from_previous']);
    }

    public function test_an_empty_workspace_reports_zeroes_rather_than_dividing_by_zero(): void
    {
        $data = $this->dashboard();

        $this->assertSame(0, collect($data['funnel'])->firstWhere('key', 'total')['count']);
        $this->assertEquals(0, collect($data['funnel'])->firstWhere('key', 'won')['of_total']);
        $this->assertNull($data['response_time']['average_seconds']);
    }

    public function test_temperature_counts_exclude_closed_leads(): void
    {
        // A won deal is not "work left to do", so it does not belong in a
        // hot/warm/cold split a rep uses to decide who to call.
        $this->lead('مفتوح حار', LeadStatus::New, temperature: 'hot');
        $this->lead('مكسوب حار', LeadStatus::Won, temperature: 'hot');
        $this->lead('مفقود حار', LeadStatus::Lost, temperature: 'hot');

        $quality = collect($this->dashboard()['quality'])->keyBy('key');

        $this->assertSame(1, $quality['hot']['count']);
        $this->assertEquals(100, $quality['hot']['share']);
    }

    public function test_response_time_is_a_distribution_not_an_average(): void
    {
        $this->contactedAfter('سريع', minutes: 2);
        $this->contactedAfter('متوسط', minutes: 9);
        $this->contactedAfter('بطيء', minutes: 22);
        $this->contactedAfter('متأخر جدًا', minutes: 300);

        $response = $this->dashboard()['response_time'];
        $buckets = collect($response['buckets'])->keyBy('key');

        $this->assertSame(1, $buckets['under_5']['count']);
        $this->assertSame(1, $buckets['under_15']['count']);
        $this->assertSame(1, $buckets['under_30']['count']);
        $this->assertSame(1, $buckets['over_30']['count']);
        $this->assertSame(4, $response['contacted']);

        // The median is the honest headline here: one 5-hour outlier drags the
        // average to over an hour while most leads were answered in minutes.
        $this->assertGreaterThan($response['median_seconds'], $response['average_seconds']);
    }

    public function test_leads_nobody_answered_are_reported_separately(): void
    {
        $this->contactedAfter('تم التواصل', minutes: 3);
        $this->lead('لم يتم التواصل', LeadStatus::New);

        $response = $this->dashboard()['response_time'];

        // Folding them in as zero would make an inattentive team look fast.
        $this->assertSame(1, $response['contacted']);
        $this->assertSame(1, $response['uncontacted']);
    }

    public function test_the_comparison_window_matches_the_window_being_viewed(): void
    {
        $this->leadCreatedAt('قديم', now()->subDays(20));
        $this->leadCreatedAt('قديم آخر', now()->subDays(18));
        $this->leadCreatedAt('حالي', now()->subDays(3));

        // A seven-day window compares against the seven days before it.
        $data = $this->dashboard([
            'date_from' => now()->subDays(6)->toDateString(),
            'date_to' => now()->toDateString(),
        ]);

        $this->assertNotNull($data['comparison']);
        $this->assertSame(now()->subDays(13)->toDateString(), $data['comparison']['from']);
        $this->assertSame(now()->subDays(7)->toDateString(), $data['comparison']['to']);
    }

    public function test_no_date_range_means_no_comparison_rather_than_an_invented_one(): void
    {
        $this->lead('عميل', LeadStatus::New);

        // "Everything" has no previous period. A made-up baseline is worse
        // than none, because someone will quote the percentage.
        $this->assertNull($this->dashboard()['comparison']);
    }

    public function test_the_whole_dashboard_is_one_request(): void
    {
        foreach (range(1, 20) as $i) {
            $this->lead('عميل '.$i, LeadStatus::New);
        }

        $queries = 0;
        \DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->actingAs($this->t['admin'])->getJson('/api/v1/dashboard')->assertSuccessful();

        // Charts were added without the screen becoming a request per card.
        $this->assertLessThan(30, $queries, "The dashboard issued {$queries} queries.");
    }

    private function dashboard(array $filters = []): array
    {
        return $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/dashboard?'.http_build_query($filters))
            ->assertSuccessful()
            ->json('data');
    }

    private function lead(
        string $name,
        LeadStatus $status,
        bool $qualified = false,
        bool $contacted = false,
        string $temperature = 'cold',
    ): Lead {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => $name,
            'phone' => '05'.random_int(10000000, 99999999),
            'phone_normalized' => '+9665'.random_int(10000000, 99999999),
            'status' => $status,
            'temperature' => $temperature,
            'qualification_status' => $qualified
                ? QualificationStatus::Qualified
                : QualificationStatus::Unqualified,
            'first_contact_at' => $contacted ? now()->subHour() : null,
        ]));
    }

    private function contactedAfter(string $name, int $minutes): void
    {
        $lead = $this->lead($name, LeadStatus::Contacted);

        $lead->forceFill([
            'created_at' => now()->subDay(),
            'first_contact_at' => now()->subDay()->addMinutes($minutes),
        ])->saveQuietly();
    }

    private function leadCreatedAt(string $name, $at): void
    {
        $this->lead($name, LeadStatus::New)->forceFill(['created_at' => $at])->saveQuietly();
    }
}
