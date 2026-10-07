<?php

namespace Tests\Feature;

use App\Domain\Leads\OutcomeRecorder;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the rep just did has to reach the rules.
 *
 * Every other lead event marks a change of state, and the recorder announced
 * one only when the lead moved forward. "No answer" and "call later" move
 * nothing — they are the two most common buttons on a call list — so they
 * reached the automation engine as nothing at all.
 *
 * The consequence was not subtle: "after two unanswered calls, send a
 * WhatsApp" could not be written, and a no-reply sequence is most of what a
 * follow-up automation is for.
 */
class OutcomeTriggersAutomationTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Outcome Co');
    }

    private function rule(array $conditions = []): AutomationRule
    {
        return $this->asTenant($this->t['tenant'], fn () => AutomationRule::create([
            'name' => 'قاعدة النتيجة',
            'trigger' => 'lead.outcome_recorded',
            'conditions' => $conditions,
            'actions' => [['type' => 'add_note', 'body' => 'عاودنا الاتصال']],
            'is_enabled' => true,
        ]));
    }

    private function lead(): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => 'عميل',
            'phone_normalized' => '+9665003'.random_int(10000, 99999),
            'status' => 'contacted',
            'assigned_to' => $this->t['admin']->id,
        ]));
    }

    private function record(Lead $lead, string $outcome): void
    {
        $this->asTenant(
            $this->t['tenant'],
            fn () => app(OutcomeRecorder::class)->record($lead, $outcome, $this->t['admin']),
        );
    }

    private function runs(AutomationRule $rule): int
    {
        return AutomationRun::withoutGlobalScopes()
            ->where('automation_rule_id', $rule->id)
            ->where('outcome', 'applied')
            ->count();
    }

    /**
     * The gap this was written for: the lead is already `contacted`, so "no
     * answer" advances nothing and used to announce nothing.
     */
    public function test_an_outcome_that_moves_no_stage_still_reaches_the_rules(): void
    {
        $rule = $this->rule();
        $lead = $this->lead();

        $this->record($lead, 'no_answer');

        $this->assertSame(1, $this->runs($rule));
    }

    /**
     * Without a readable outcome the trigger fires for every button in the
     * work queue and a rule cannot tell "no answer" from "interested" — which
     * would make it useless for the sequence it exists to enable.
     */
    public function test_a_rule_can_tell_one_outcome_from_another(): void
    {
        $rule = $this->rule([['field' => 'last_outcome', 'operator' => 'equals', 'value' => 'no_answer']]);
        $lead = $this->lead();

        $this->record($lead, 'call_later');
        $this->assertSame(0, $this->runs($rule), 'a different outcome must not match');

        $this->record($lead, 'no_answer');
        $this->assertSame(1, $this->runs($rule));
    }

    /** An outcome that does advance the lead announces both, not one or other. */
    public function test_an_advancing_outcome_announces_the_outcome_and_the_stage(): void
    {
        $outcomeRule = $this->rule();

        $stageRule = $this->asTenant($this->t['tenant'], fn () => AutomationRule::create([
            'name' => 'قاعدة المرحلة',
            'trigger' => 'lead.stage_changed',
            'conditions' => [],
            'actions' => [['type' => 'add_note', 'body' => 'تقدّم']],
            'is_enabled' => true,
        ]));

        $this->record($this->lead(), 'interested');

        $this->assertSame(1, $this->runs($outcomeRule));
        $this->assertSame(1, $this->runs($stageRule));
    }

    public function test_it_never_fires_a_rule_from_another_workspace(): void
    {
        $other = $this->provisionTenant('Somebody Else');
        $foreign = $this->asTenant($other['tenant'], fn () => AutomationRule::create([
            'name' => 'قاعدة غريبة',
            'trigger' => 'lead.outcome_recorded',
            'conditions' => [],
            'actions' => [['type' => 'add_note', 'body' => 'لا']],
            'is_enabled' => true,
        ]));

        $this->record($this->lead(), 'no_answer');

        $this->assertSame(0, $this->runs($foreign));
    }
}
