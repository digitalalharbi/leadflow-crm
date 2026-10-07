<?php

namespace Tests\Feature;

use App\Domain\Leads\OutcomeRecorder;
use App\Enums\QualificationStatus;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\LeadAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A lead's stage and the timestamps that prove it must not disagree.
 *
 * Every one of these timestamps is set in exactly one controller, and every
 * stage can be reached from at least three other places — the work queue, the
 * automation engine, an import. The stage moves, the timestamp stays null, and
 * the lead is in two states at once: the pipeline shows it qualified and every
 * report that measures qualification cannot see it.
 *
 * This is the same shape as the `won_at` bug, which is why the rule lives on
 * the model rather than in a fourth controller that has to remember.
 */
class LeadStateInvariantTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('State Co');
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'عميل',
            'phone_normalized' => '+9665008'.random_int(10000, 99999),
            'status' => 'new',
        ], $attributes)));
    }

    /**
     * The funnel chart counts `qualified_at` and the velocity chart measures
     * two of its three steps from it. Only the qualification screen ever set
     * it, so a lead qualified from the work queue — the product's own headline
     * flow — was missing from both.
     */
    public function test_a_qualified_lead_carries_the_moment_it_qualified(): void
    {
        $this->assertNotNull($this->lead(['status' => 'qualified'])->fresh()->qualified_at);
    }

    /** Past qualification is still past it. */
    public function test_a_lead_beyond_qualification_carries_it_too(): void
    {
        foreach (['appointment', 'negotiation', 'won'] as $status) {
            $this->assertNotNull(
                $this->lead(['status' => $status])->fresh()->qualified_at,
                "a lead at {$status} must carry qualified_at",
            );
        }
    }

    /** A lead that never got that far carries nothing. */
    public function test_an_unqualified_lead_carries_no_qualification_moment(): void
    {
        $this->assertNull($this->lead(['status' => 'contacted'])->fresh()->qualified_at);
    }

    /** The rep's own button is the path that matters most. */
    public function test_the_work_queue_qualifies_a_lead_visibly_to_the_reports(): void
    {
        $lead = $this->lead(['status' => 'contacted', 'assigned_to' => $this->t['admin']->id]);

        $this->asTenant(
            $this->t['tenant'],
            fn () => app(OutcomeRecorder::class)->record($lead, 'interested', $this->t['admin']),
        );

        $this->assertNotNull($lead->fresh()->qualified_at);
    }

    /**
     * The qualification *status* is deliberately not touched here. Its enum
     * says so outright: a lead can be contacted and still unqualified, and a
     * qualified lead can be lost — it describes how much is known about the
     * person, not where they sit in the funnel. Only the timestamp the reports
     * read is backfilled.
     */
    public function test_moving_the_stage_does_not_decide_the_qualification_record(): void
    {
        $this->assertSame(
            QualificationStatus::Unqualified,
            $this->lead(['status' => 'qualified'])->fresh()->qualification_status,
        );
    }

    /**
     * The dashboard counts a reply as `first_response_at is not null`, and the
     * sales report measures time-to-first-reply from it. A lead moved to
     * "replied" by a rule or by hand was a reply nothing could see.
     */
    public function test_a_lead_that_replied_carries_the_moment_it_replied(): void
    {
        $this->assertNotNull($this->lead(['status' => 'replied'])->fresh()->first_response_at);
    }

    public function test_a_lead_that_never_replied_carries_no_reply(): void
    {
        $this->assertNull($this->lead(['status' => 'qualified'])->fresh()->first_response_at);
    }

    /** The first reply cannot predate the first contact that prompted it. */
    public function test_a_reply_is_never_stamped_before_the_contact(): void
    {
        $lead = $this->lead(['status' => 'replied'])->fresh();

        $this->assertNotNull($lead->first_contact_at);
        $this->assertTrue($lead->first_response_at >= $lead->first_contact_at);
    }

    /** An owner and the moment they got it travel together. */
    public function test_an_assigned_lead_carries_the_moment_it_was_assigned(): void
    {
        $lead = $this->lead();
        $lead->forceFill(['assigned_to' => $this->t['admin']->id])->save();

        $this->assertNotNull($lead->fresh()->assigned_at);
    }

    public function test_an_unassigned_lead_carries_no_assignment_moment(): void
    {
        $lead = $this->lead(['assigned_to' => $this->t['admin']->id]);
        $lead->forceFill(['assigned_to' => null])->save();

        $this->assertNull($lead->fresh()->assigned_at);
    }

    /** The service already got this right, and must keep its own timestamp. */
    public function test_the_assignment_service_still_sets_its_own_moment(): void
    {
        $rep = User::create([
            'tenant_id' => $this->t['tenant']->id,
            'name' => 'مندوب', 'email' => 'rep@state.test',
            'password' => bcrypt('secret'), 'role' => 'sales', 'is_active' => true,
        ]);

        $lead = $this->lead();

        $this->asTenant(
            $this->t['tenant'],
            fn () => app(LeadAssignmentService::class)->assign($lead, $rep, $this->t['admin']),
        );

        $fresh = $lead->fresh();
        $this->assertSame($rep->id, $fresh->assigned_to);
        $this->assertNotNull($fresh->assigned_at);
    }
}
