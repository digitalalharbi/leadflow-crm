<?php

namespace Tests\Feature;

use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How long leads sit at each step before moving.
 *
 * The question a funnel cannot answer. A funnel says ten of forty reached an
 * appointment; it says nothing about the ones that waited three weeks for a
 * first call, which is the part a manager can act on this afternoon.
 */
class VelocityAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Velocity Co');
    }

    private function lead(array $times): Lead
    {
        return $this->asTenant($this->t['tenant'], function () use ($times) {
            $lead = Lead::create([
                'full_name' => 'عميل',
                'phone_normalized' => '+9665009'.random_int(10000, 99999),
                'status' => $times['status'] ?? 'new',
            ]);

            $lead->forceFill(array_diff_key($times, ['status' => null]))->saveQuietly();

            return $lead->fresh();
        });
    }

    private function velocity(): array
    {
        return collect(
            $this->actingAs($this->t['admin'])
                ->getJson('/api/v1/dashboard')->assertOk()->json('data.velocity')
        )->keyBy('step')->all();
    }

    public function test_it_reports_the_three_steps(): void
    {
        $this->assertSame(
            ['to_contact', 'to_qualify', 'to_win'],
            array_keys($this->velocity()),
        );
    }

    public function test_it_measures_the_hours_between_two_stamps(): void
    {
        $this->lead([
            'created_at' => now()->subHours(10),
            'first_contact_at' => now()->subHours(6),
        ]);

        $step = $this->velocity()['to_contact'];

        // JSON renders 4.0 as 4, so compare the value rather than the type.
        $this->assertEqualsWithDelta(4.0, (float) $step['median_hours'], 0.05);
        $this->assertSame(1, $step['count']);
    }

    /**
     * Median, never mean. One lead nobody closed drags a mean into uselessness,
     * and it is exactly the lead the sales floor already knows about.
     */
    public function test_one_forgotten_lead_does_not_move_the_number(): void
    {
        foreach ([2, 3, 4] as $hours) {
            $this->lead([
                'created_at' => now()->subHours($hours + 1),
                'first_contact_at' => now()->subHours(1),
            ]);
        }

        // The one from last year.
        $this->lead([
            'created_at' => now()->subDays(400),
            'first_contact_at' => now()->subHours(1),
        ]);

        $step = $this->velocity()['to_contact'];

        // A mean here would be well over two thousand hours.
        $this->assertLessThan(10, $step['median_hours']);
        $this->assertSame(4, $step['count']);
    }

    /**
     * Only leads that made the move. Counting the ones still waiting mixes
     * "took two days" with "has waited two days and may never move" — a
     * different measurement, and one the funnel already shows.
     */
    public function test_a_lead_that_never_moved_is_not_counted(): void
    {
        $this->lead(['created_at' => now()->subDays(5)]);

        $step = $this->velocity()['to_contact'];

        $this->assertSame(0, $step['count']);
        $this->assertNull($step['median_hours']);
    }

    /** A clock that ran backwards is a data problem, not a fast rep. */
    public function test_a_contact_stamped_before_the_lead_arrived_is_excluded(): void
    {
        $this->lead([
            'created_at' => now()->subHours(2),
            'first_contact_at' => now()->subHours(5),
        ]);

        $this->assertSame(0, $this->velocity()['to_contact']['count']);
    }

    public function test_it_never_measures_another_workspace(): void
    {
        $other = $this->provisionTenant('Elsewhere Co');

        $this->asTenant($other['tenant'], function () {
            $lead = Lead::create([
                'full_name' => 'سر', 'phone_normalized' => '+966500666555', 'status' => 'new',
            ]);
            $lead->forceFill([
                'created_at' => now()->subHours(9),
                'first_contact_at' => now(),
            ])->saveQuietly();
        });

        $this->assertSame(0, $this->velocity()['to_contact']['count']);
    }
}
