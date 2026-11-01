<?php

namespace Tests\Feature;

use App\Domain\Automation\ActionExecutor;
use App\Domain\Leads\OutcomeRecorder;
use App\Models\FollowUp;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `leads.next_follow_up_at` says when the lead is next due, and three screens
 * believe it.
 *
 * Seven places write follow-ups and three of them also set that column by hand,
 * so the one that mattered most did not: a follow-up booked by the rep's own
 * outcome in the work queue left the lead with no scheduled step at all.
 * Nothing looked wrong — the row existed and the follow-ups screen showed it —
 * but everything reading the column was blind to it.
 */
class FollowUpScheduleTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Schedule Co');
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'عميل',
            'phone_normalized' => '+96650011'.self::uniqueDigits(4),
            'status' => 'contacted',
            'assigned_to' => $this->t['admin']->id,
        ], $attributes)));
    }

    private function record(Lead $lead, string $outcome): void
    {
        $this->asTenant(
            $this->t['tenant'],
            fn () => app(OutcomeRecorder::class)->record($lead, $outcome, $this->t['admin']),
        );
    }

    public function test_an_outcome_that_books_a_reminder_schedules_the_lead(): void
    {
        $lead = $this->lead();

        $this->record($lead, 'no_answer');

        $this->assertNotNull($lead->fresh()->next_follow_up_at);
    }

    /**
     * The cost of the bug, as the rep met it.
     *
     * "لم يرد" answers with "سُجِّلت المحاولة، وسنذكّرك بعد ثلاث ساعات".
     * `NextBestAction` only says "wait" when the column is set, so without it
     * the lead looked like one that needed calling and the queue handed the
     * same name straight back — under a message promising otherwise.
     */
    public function test_a_lead_just_worked_is_not_handed_straight_back(): void
    {
        $lead = $this->lead();
        $this->lead(['full_name' => 'عميل آخر']);

        $this->record($lead, 'no_answer');

        $next = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/work/next')
            ->assertOk()
            ->json('data.lead.id');

        $this->assertNotSame($lead->uuid, $next);
    }

    /** The earliest open step, not the most recent one written. */
    public function test_it_points_at_the_soonest_pending_step(): void
    {
        $lead = $this->lead();

        $this->asTenant($this->t['tenant'], function () use ($lead) {
            FollowUp::create([
                'lead_id' => $lead->id, 'assigned_to' => $this->t['admin']->id,
                'due_at' => now()->addDays(3), 'status' => FollowUp::PENDING,
            ]);
            FollowUp::create([
                'lead_id' => $lead->id, 'assigned_to' => $this->t['admin']->id,
                'due_at' => now()->addHours(2), 'status' => FollowUp::PENDING,
            ]);
        });

        $this->assertSame(
            now()->addHours(2)->startOfMinute()->toDateTimeString(),
            $lead->fresh()->next_follow_up_at->startOfMinute()->toDateTimeString(),
        );
    }

    /** A lead with nothing open is due nothing, and must not read as overdue. */
    public function test_cancelling_the_last_step_clears_the_schedule(): void
    {
        $lead = $this->lead();

        $followUp = $this->asTenant($this->t['tenant'], fn () => FollowUp::create([
            'lead_id' => $lead->id, 'assigned_to' => $this->t['admin']->id,
            'due_at' => now()->addHours(2), 'status' => FollowUp::PENDING,
        ]));

        $this->assertNotNull($lead->fresh()->next_follow_up_at);

        $followUp->update(['status' => FollowUp::CANCELLED]);

        $this->assertNull($lead->fresh()->next_follow_up_at);
    }

    /**
     * Closing a lead cancels its steps through a mass update, which fires no
     * model events — so the derived column would have kept pointing at a
     * reminder that no longer exists, and the lead would have read as overdue
     * forever after it was won.
     */
    public function test_closing_a_lead_clears_the_schedule_too(): void
    {
        $lead = $this->lead();
        $this->record($lead, 'no_answer');
        $this->assertNotNull($lead->fresh()->next_follow_up_at);

        $this->record($lead, 'won');

        $this->assertNull($lead->fresh()->next_follow_up_at);
    }

    /** The same path, reached by a rule rather than by a rep. */
    public function test_a_rule_that_stops_follow_ups_clears_the_schedule(): void
    {
        $lead = $this->lead();
        $this->record($lead, 'no_answer');
        $this->assertNotNull($lead->fresh()->next_follow_up_at);

        $this->asTenant($this->t['tenant'], fn () => app(ActionExecutor::class)
            ->execute($lead->fresh(), ['type' => 'stop_follow_ups']));

        $this->assertNull($lead->fresh()->next_follow_up_at);
    }

    public function test_completing_a_step_moves_the_lead_to_the_next_one(): void
    {
        $lead = $this->lead();

        [$soon, $later] = $this->asTenant($this->t['tenant'], fn () => [
            FollowUp::create([
                'lead_id' => $lead->id, 'assigned_to' => $this->t['admin']->id,
                'due_at' => now()->addHours(2), 'status' => FollowUp::PENDING,
            ]),
            FollowUp::create([
                'lead_id' => $lead->id, 'assigned_to' => $this->t['admin']->id,
                'due_at' => now()->addDays(2), 'status' => FollowUp::PENDING,
            ]),
        ]);

        $soon->update(['status' => FollowUp::COMPLETED, 'completed_at' => now()]);

        $this->assertSame(
            $later->due_at->startOfMinute()->toDateTimeString(),
            $lead->fresh()->next_follow_up_at->startOfMinute()->toDateTimeString(),
        );
    }
}
