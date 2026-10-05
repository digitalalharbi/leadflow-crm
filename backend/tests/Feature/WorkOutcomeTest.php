<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Models\FollowUp;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The loop the product is sold on: a rep presses one thing, and the system
 * records the result, moves the stage, books the next step, lets the rules see
 * it, and hands over the next lead — without the rep visiting another screen.
 *
 * Each test below is one way that loop can break while still looking fine.
 */
class WorkOutcomeTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Outcome Co');
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'عميل '.random_int(100, 999),
            'phone_normalized' => '+9665002'.random_int(10000, 99999),
            'status' => 'new',
            'assigned_to' => $this->t['admin']->id,
        ], $attributes)));
    }

    private function record(Lead $lead, string $outcome, array $extra = [])
    {
        return $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/work/outcome', array_merge([
                'lead_id' => $lead->uuid,
                'outcome' => $outcome,
            ], $extra));
    }

    public function test_one_press_records_the_stage_and_the_next_step(): void
    {
        $lead = $this->lead();

        $this->record($lead, 'interested')->assertOk();

        $fresh = $lead->fresh();
        $this->assertSame(LeadStatus::Qualified, $fresh->status);
        $this->assertNotNull($fresh->first_contact_at, 'qualifying implies contact happened');

        $step = FollowUp::where('lead_id', $lead->id)->first();
        $this->assertNotNull($step, 'a lead with no next step is a lead nobody calls again');
        $this->assertTrue($step->due_at->isFuture());
    }

    /** The whole point of one round trip: the next lead arrives with the result. */
    public function test_the_response_carries_the_next_lead(): void
    {
        $first = $this->lead(['score' => 90]);
        $second = $this->lead(['score' => 80]);

        $body = $this->record($first, 'no_answer')->assertOk()->json('data');

        $this->assertNotNull($body['next']);
        $this->assertSame($second->uuid, $body['next']['lead']['id']);
    }

    /** A rep who records an outcome and is handed the same name back stops trusting the button. */
    public function test_the_lead_just_worked_is_not_handed_straight_back(): void
    {
        $only = $this->lead();

        $body = $this->record($only, 'no_answer')->assertOk()->json('data');

        $this->assertNull($body['next']['lead'], 'the only lead was just worked; the queue is empty');
    }

    /**
     * An outcome is not allowed to undo work somebody already did. Saying
     * "interested" about a lead at negotiation must not demote it — a silent
     * demotion, as a reward for using the button, is worse than no button.
     */
    public function test_an_outcome_never_drags_a_lead_backwards(): void
    {
        $lead = $this->lead(['status' => 'negotiation']);

        $this->record($lead, 'interested')->assertOk();

        $this->assertSame(LeadStatus::Negotiation, $lead->fresh()->status);
    }

    /** But a decision applies wherever the lead sits. */
    public function test_won_and_lost_apply_even_to_a_lead_further_along(): void
    {
        $lead = $this->lead(['status' => 'negotiation']);

        $this->record($lead, 'won')->assertOk();

        $this->assertSame(LeadStatus::Won, $lead->fresh()->status);
    }

    /** Chasing a customer who already bought is what costs a workspace its trust. */
    public function test_closing_a_lead_cancels_its_pending_steps(): void
    {
        $lead = $this->lead();
        $this->record($lead, 'call_later')->assertOk();
        $this->assertSame(1, FollowUp::where('lead_id', $lead->id)->where('status', FollowUp::PENDING)->count());

        $this->record($lead, 'won')->assertOk();

        $this->assertSame(0, FollowUp::where('lead_id', $lead->id)->where('status', FollowUp::PENDING)->count());
    }

    /** Two reminders for one piece of work is two notifications and one call. */
    public function test_a_second_outcome_does_not_stack_another_reminder(): void
    {
        $lead = $this->lead();

        $this->record($lead, 'no_answer')->assertOk();
        $this->record($lead, 'call_later')->assertOk();

        $this->assertSame(1, FollowUp::where('lead_id', $lead->id)->where('status', FollowUp::PENDING)->count());
    }

    public function test_an_unknown_outcome_is_refused(): void
    {
        $this->record($this->lead(), 'maybe_later')->assertStatus(422);
    }

    public function test_a_rep_cannot_record_against_another_reps_lead(): void
    {
        $rep = $this->makeUser($this->t['tenant']);
        $lead = $this->lead(['assigned_to' => $this->t['admin']->id]);

        $this->actingAs($rep)
            ->postJson('/api/v1/work/outcome', ['lead_id' => $lead->uuid, 'outcome' => 'won'])
            ->assertForbidden();
    }

    public function test_another_tenants_lead_is_not_found(): void
    {
        $other = $this->provisionTenant('Elsewhere');
        $theirs = $this->asTenant($other['tenant'], fn () => Lead::create([
            'full_name' => 'سر', 'phone_normalized' => '+966500999111', 'status' => 'new',
        ]));

        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/work/outcome', ['lead_id' => $theirs->uuid, 'outcome' => 'won'])
            ->assertNotFound();
    }
}
