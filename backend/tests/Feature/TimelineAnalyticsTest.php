<?php

namespace Tests\Feature;

use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What happened each day.
 *
 * The question that decides whether the chart is honest is which day a win
 * lands on: the day the deal closed, or the day the lead first arrived. These
 * tests pin the first, because the second produces a line that falls off a
 * cliff at the right-hand edge — recent cohorts have not had time to convert —
 * and everyone reads that cliff as the business collapsing.
 */
class TimelineAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Timeline Co');
    }

    private function lead(array $attributes): Lead
    {
        return $this->asTenant($this->t['tenant'], function () use ($attributes) {
            $lead = Lead::create(array_merge([
                'full_name' => 'عميل',
                'phone_normalized' => '+9665007'.self::uniqueDigits(5),
                'status' => 'new',
            ], array_diff_key($attributes, ['created_at' => null])));

            if (isset($attributes['created_at'])) {
                $lead->forceFill(['created_at' => $attributes['created_at']])->saveQuietly();
            }

            return $lead->fresh();
        });
    }

    private function timeline(int $days = 30): array
    {
        return $this->actingAs($this->t['admin'])
            ->getJson("/api/v1/dashboard?timeline_days={$days}")
            ->assertOk()
            ->json('data.timeline');
    }

    public function test_it_returns_a_point_for_every_day_including_the_quiet_ones(): void
    {
        $points = $this->timeline(14);

        $this->assertCount(14, $points);
        // A series that skips empty days draws a straight line across a weekend
        // and reads as steady business rather than a closed one.
        $this->assertSame(now()->subDays(13)->toDateString(), $points[0]['date']);
        $this->assertSame(now()->toDateString(), $points[13]['date']);
    }

    /** The point of the whole thing. */
    public function test_a_win_lands_on_the_day_it_closed_not_the_day_the_lead_arrived(): void
    {
        $this->lead([
            'created_at' => now()->subDays(20),
            'status' => 'won',
            'won_at' => now()->subDay(),
            'first_contact_at' => now()->subDays(19),
        ]);

        $points = collect($this->timeline(30))->keyBy('date');

        $this->assertSame(1, $points[now()->subDay()->toDateString()]['won']);
        $this->assertSame(0, $points[now()->subDays(20)->toDateString()]['won']);
        // And it still counts as a lead on the day it arrived.
        $this->assertSame(1, $points[now()->subDays(20)->toDateString()]['leads']);
    }

    public function test_qualification_lands_on_its_own_day(): void
    {
        $this->lead([
            'created_at' => now()->subDays(5),
            'status' => 'qualified',
            'qualified_at' => now()->subDays(2),
            'first_contact_at' => now()->subDays(4),
        ]);

        $points = collect($this->timeline(10))->keyBy('date');

        $this->assertSame(1, $points[now()->subDays(2)->toDateString()]['qualified']);
        $this->assertSame(0, $points[now()->subDays(5)->toDateString()]['qualified']);
    }

    /** Anything older than the window is out, not clamped onto day one. */
    public function test_events_before_the_window_are_not_piled_onto_the_first_day(): void
    {
        $this->lead(['created_at' => now()->subDays(90)]);

        $points = $this->timeline(14);

        $this->assertSame(0, $points[0]['leads']);
        $this->assertSame(0, array_sum(array_column($points, 'leads')));
    }

    /**
     * The line is thirty days whatever the filter says. Cropping it to a
     * seven-day range leaves four points and still calls itself a trend.
     */
    public function test_the_date_filter_does_not_shorten_the_line(): void
    {
        $this->lead(['created_at' => now()->subDays(20)]);

        $points = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/dashboard?timeline_days=30&date_from='.now()->subDays(2)->toDateString())
            ->assertOk()
            ->json('data.timeline');

        $this->assertCount(30, $points);
        $this->assertSame(1, array_sum(array_column($points, 'leads')));
    }

    public function test_it_never_counts_another_workspace(): void
    {
        $other = $this->provisionTenant('Elsewhere Co');

        $this->asTenant($other['tenant'], fn () => Lead::create([
            'full_name' => 'سر', 'phone_normalized' => '+966500888777', 'status' => 'new',
        ]));

        $this->assertSame(0, array_sum(array_column($this->timeline(14), 'leads')));
    }
}
