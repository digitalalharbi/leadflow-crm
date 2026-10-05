<?php

namespace Tests\Feature;

use App\Domain\Leads\NextBestAction;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the rep should do next, and why.
 *
 * The advice has to be right in priority order, because the whole point is that
 * the rep stops judging. Advice that puts a scheduled follow-up ahead of a
 * customer who just replied is worse than no advice: it is confidently wrong,
 * and it costs the deal that was closest to closing.
 */
class NextBestActionTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private NextBestAction $nba;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('NBA Co');
        $this->nba = app(NextBestAction::class);
        $this->t['tenant']->settings->forceFill(['first_contact_target_minutes' => 30])->save();
    }

    private function lead(array $attributes = [], int $ageMinutes = 1): Lead
    {
        $rep = $this->makeUser($this->t['tenant']);

        return $this->asTenant($this->t['tenant'], function () use ($attributes, $ageMinutes, $rep) {
            $lead = Lead::create(array_merge([
                'full_name' => 'عميل',
                'phone_normalized' => '+9665000'.random_int(10000, 99999),
                'status' => 'new',
                'assigned_to' => $rep->id,
            ], $attributes));

            $lead->forceFill(['created_at' => now()->subMinutes($ageMinutes)])->saveQuietly();

            return $lead->fresh();
        });
    }

    public function test_a_closed_lead_needs_nothing(): void
    {
        $action = $this->nba->for($this->lead(['status' => 'won']), 30);

        $this->assertSame('none', $action['action']);
    }

    public function test_an_unassigned_lead_asks_to_be_assigned_first(): void
    {
        $lead = $this->lead();
        $lead->forceFill(['assigned_to' => null])->saveQuietly();

        $action = $this->nba->for($lead->fresh(), 30);

        $this->assertSame('assign', $action['action']);
    }

    /** The most expensive failure in this business, so it must rank highest. */
    public function test_an_uncontacted_lead_past_the_target_is_critical(): void
    {
        $action = $this->nba->for($this->lead([], ageMinutes: 45), 30);

        $this->assertSame('call_now', $action['action']);
        $this->assertSame('critical', $action['urgency']);
        $this->assertStringContainsString('45', $action['reason']);
    }

    public function test_a_fresh_lead_says_how_long_is_left(): void
    {
        $action = $this->nba->for($this->lead([], ageMinutes: 10), 30);

        $this->assertSame('call_now', $action['action']);
        $this->assertSame('high', $action['urgency']);
        $this->assertSame(20, $action['due_in_minutes']);
    }

    /**
     * A reply is the strongest buying signal there is and the window is short,
     * so it must outrank a follow-up that happens to be scheduled.
     */
    public function test_a_reply_outranks_a_scheduled_follow_up(): void
    {
        $lead = $this->lead([
            'status' => 'replied',
            'first_contact_at' => now()->subHour(),
            'first_response_at' => now()->subMinutes(5),
            'next_follow_up_at' => now()->addHour(),
        ], ageMinutes: 120);

        $action = $this->nba->for($lead, 30);

        $this->assertSame('reply_now', $action['action']);
        $this->assertSame('critical', $action['urgency']);
    }

    public function test_an_overdue_follow_up_is_critical_and_says_how_late(): void
    {
        $lead = $this->lead([
            'status' => 'contacted',
            'first_contact_at' => now()->subHours(3),
            'next_follow_up_at' => now()->subMinutes(20),
        ], ageMinutes: 200);

        $action = $this->nba->for($lead, 30);

        $this->assertSame('follow_up', $action['action']);
        $this->assertSame('critical', $action['urgency']);
        $this->assertStringContainsString('20', $action['reason']);
    }

    /** The lead that quietly goes cold, because nothing about it looks urgent. */
    public function test_a_qualified_lead_with_no_next_step_is_told_to_book(): void
    {
        $lead = $this->lead([
            'status' => 'qualified',
            'first_contact_at' => now()->subDay(),
        ], ageMinutes: 1500);

        $action = $this->nba->for($lead, 30);

        $this->assertSame('book_meeting', $action['action']);
        $this->assertSame('high', $action['urgency']);
    }

    public function test_a_scheduled_follow_up_far_out_needs_nothing_now(): void
    {
        $lead = $this->lead([
            'status' => 'contacted',
            'first_contact_at' => now()->subHours(2),
            'next_follow_up_at' => now()->addDays(2),
        ], ageMinutes: 130);

        $action = $this->nba->for($lead, 30);

        $this->assertSame('wait', $action['action']);
        $this->assertSame('low', $action['urgency']);
    }

    public function test_every_action_explains_itself(): void
    {
        foreach ([
            $this->lead([], ageMinutes: 45),
            $this->lead(['status' => 'won']),
            $this->lead(['status' => 'qualified', 'first_contact_at' => now()->subDay()], ageMinutes: 1500),
        ] as $lead) {
            $action = $this->nba->for($lead, 30);

            $this->assertNotEmpty($action['reason'], 'Advice without a reason will be ignored.');
        }
    }
}
