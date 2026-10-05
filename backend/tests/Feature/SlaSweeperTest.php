<?php

namespace Tests\Feature;

use App\Domain\Sla\SlaSweeper;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The only part of the system that notices silence.
 *
 * Its failure modes are the interesting ones: nagging a rep repeatedly for the
 * same breach destroys trust in the notifications faster than missing one, and
 * taking a lead away from someone who is already working it is worse than
 * leaving it alone.
 */
class SlaSweeperTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private SlaSweeper $sweeper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('SLA Co');
        $this->sweeper = app(SlaSweeper::class);

        $this->t['tenant']->settings->forceFill([
            'assignment_mode' => 'round_robin',
            'sla_remind_after_minutes' => 10,
            'sla_escalate_after_minutes' => 30,
            'sla_reassign_after_minutes' => 60,
        ])->save();
    }

    private function staleLead(int $ageMinutes, array $attributes = []): Lead
    {
        $rep = $this->makeUser($this->t['tenant']);

        return $this->asTenant($this->t['tenant'], function () use ($ageMinutes, $attributes, $rep) {
            $lead = Lead::create(array_merge([
                'full_name' => 'عميل متأخر',
                'phone_normalized' => '+9665000'.random_int(10000, 99999),
                'status' => 'new',
                'assigned_to' => $rep->id,
            ], $attributes));

            $lead->forceFill(['created_at' => now()->subMinutes($ageMinutes)])->saveQuietly();

            return $lead->fresh();
        });
    }

    public function test_a_fresh_lead_is_left_alone(): void
    {
        $this->staleLead(2);

        $counts = $this->sweeper->sweep($this->t['tenant']);

        $this->assertSame(['reminded' => 0, 'escalated' => 0, 'reassigned' => 0], $counts);
    }

    public function test_a_lead_past_the_reminder_window_is_reminded(): void
    {
        $lead = $this->staleLead(15);

        $counts = $this->sweeper->sweep($this->t['tenant']);

        $this->assertSame(1, $counts['reminded']);
        $this->assertNotNull($lead->fresh()->sla_reminded_at);
    }

    /** The failure that makes people turn notifications off. */
    public function test_a_breach_is_never_reported_twice(): void
    {
        $this->staleLead(15);

        $this->sweeper->sweep($this->t['tenant']);
        $second = $this->sweeper->sweep($this->t['tenant']);

        $this->assertSame(0, $second['reminded'], 'The same breach was reported twice.');
    }

    public function test_the_ladder_climbs_as_the_lead_ages(): void
    {
        $this->staleLead(45);

        $counts = $this->sweeper->sweep($this->t['tenant']);

        $this->assertSame(1, $counts['reminded']);
        $this->assertSame(1, $counts['escalated']);
        $this->assertSame(0, $counts['reassigned'], 'Reassigned before its window.');
    }

    public function test_a_long_ignored_lead_is_given_to_someone_else(): void
    {
        $lead = $this->staleLead(90);
        $original = $lead->assigned_to;

        // A second rep, so there is somebody to hand it to.
        $this->makeUser($this->t['tenant']);

        $counts = $this->sweeper->sweep($this->t['tenant']);

        $this->assertSame(1, $counts['reassigned']);
        $this->assertNotSame($original, $lead->fresh()->assigned_to);
    }

    /** Churning the timeline to hand a lead back to the same person helps nobody. */
    public function test_it_does_not_reassign_when_there_is_nobody_else(): void
    {
        $lead = $this->staleLead(90);
        $original = $lead->assigned_to;

        $counts = $this->sweeper->sweep($this->t['tenant']);

        $this->assertSame(0, $counts['reassigned']);
        $this->assertSame($original, $lead->fresh()->assigned_to);
    }

    public function test_a_contacted_lead_is_not_chased(): void
    {
        $this->staleLead(90, ['first_contact_at' => now()->subMinutes(80)]);

        $counts = $this->sweeper->sweep($this->t['tenant']);

        $this->assertSame(['reminded' => 0, 'escalated' => 0, 'reassigned' => 0], $counts);
    }

    public function test_a_closed_lead_is_not_chased(): void
    {
        $this->staleLead(90, ['status' => 'lost']);

        $counts = $this->sweeper->sweep($this->t['tenant']);

        $this->assertSame(0, $counts['reminded']);
    }

    /** An unowned lead is a different problem, and reassigning it would hide it. */
    public function test_an_unassigned_lead_is_left_for_the_dashboard(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            $lead = Lead::create([
                'full_name' => 'بلا مسؤول',
                'phone_normalized' => '+966500099999',
                'status' => 'new',
            ]);
            $lead->forceFill(['created_at' => now()->subMinutes(120), 'assigned_to' => null])->saveQuietly();
        });

        $counts = $this->sweeper->sweep($this->t['tenant']);

        $this->assertSame(0, array_sum($counts));
    }

    /** A rung set to zero is off, and must not stop the rungs after it. */
    public function test_a_disabled_rung_is_skipped_without_blocking_the_others(): void
    {
        $this->t['tenant']->settings->forceFill(['sla_remind_after_minutes' => 0])->save();
        $this->staleLead(45);

        $counts = $this->sweeper->sweep($this->t['tenant']);

        $this->assertSame(0, $counts['reminded']);
        $this->assertSame(1, $counts['escalated']);
    }
}
