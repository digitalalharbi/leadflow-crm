<?php

namespace Tests\Feature;

use App\Domain\Automation\AutomationEngine;
use App\Events\LeadCreated;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\FollowUp;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The engine is the product's claim: a lead arrives and the system acts without
 * anyone touching it. These tests are about the ways that claim can go wrong —
 * acting on the wrong lead, acting twice, looping, or acting invisibly.
 */
class AutomationEngineTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private AutomationEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('Automation Co');
        $this->engine = app(AutomationEngine::class);
    }

    private function rule(array $attributes = []): AutomationRule
    {
        return $this->asTenant($this->t['tenant'], fn () => AutomationRule::create(array_merge([
            'name' => 'Hot leads get a follow-up',
            'trigger' => 'lead.created',
            'conditions' => [['field' => 'score', 'operator' => 'greater_than', 'value' => 50]],
            'actions' => [['type' => 'schedule_follow_up', 'in_minutes' => 30]],
        ], $attributes)));
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'عميل الأتمتة',
            'phone_normalized' => '+9665000'.random_int(10000, 99999),
            'status' => 'new',
            'score' => 80,
        ], $attributes)));
    }

    public function test_a_matching_rule_acts_on_the_lead(): void
    {
        $this->rule();
        $lead = $this->lead();

        $this->asTenant($this->t['tenant'], function () use ($lead) {
            $this->engine->handle(new LeadCreated($lead));

            $this->assertDatabaseHas('follow_ups', ['lead_id' => $lead->id, 'status' => 'pending']);
        });
    }

    public function test_a_rule_that_does_not_match_leaves_the_lead_alone(): void
    {
        $this->rule();
        $lead = $this->lead(['score' => 10]);

        $this->asTenant($this->t['tenant'], function () use ($lead) {
            $this->engine->handle(new LeadCreated($lead));

            $this->assertDatabaseMissing('follow_ups', ['lead_id' => $lead->id]);
        });
    }

    /**
     * The question a manager actually asks. A log that only records what fired
     * cannot answer "why did nothing happen?", so the skipped rule is recorded
     * with the condition that rejected it.
     */
    public function test_a_skipped_rule_records_why(): void
    {
        $rule = $this->rule();
        $lead = $this->lead(['score' => 10]);

        $this->asTenant($this->t['tenant'], function () use ($lead, $rule) {
            $this->engine->handle(new LeadCreated($lead));

            $run = AutomationRun::where('lead_id', $lead->id)->first();

            $this->assertNotNull($run, 'Nothing was recorded for a rule that was considered.');
            $this->assertSame('skipped', $run->outcome);
            $this->assertSame($rule->id, $run->automation_rule_id);
            $this->assertStringContainsString('score', $run->reason);
        });
    }

    public function test_an_applied_rule_records_what_it_did_in_words(): void
    {
        $this->rule();
        $lead = $this->lead();

        $this->asTenant($this->t['tenant'], function () use ($lead) {
            $this->engine->handle(new LeadCreated($lead));

            $run = AutomationRun::where('lead_id', $lead->id)->first();

            $this->assertSame('applied', $run->outcome);
            $this->assertSame('schedule_follow_up', $run->applied_actions[0]['type']);
            $this->assertTrue($run->applied_actions[0]['ok']);
            // Arabic, because the manager reading the trace reads Arabic.
            $this->assertStringContainsString('حُدِّدت متابعة', $run->applied_actions[0]['description']);
        });
    }

    public function test_rules_run_in_priority_order_and_can_stop_the_rest(): void
    {
        $this->rule(['name' => 'First', 'priority' => 10, 'stops_processing' => true,
            'actions' => [['type' => 'add_note', 'body' => 'من القاعدة الأولى']]]);
        $this->rule(['name' => 'Second', 'priority' => 20,
            'actions' => [['type' => 'add_note', 'body' => 'من القاعدة الثانية']]]);

        $lead = $this->lead();

        $this->asTenant($this->t['tenant'], function () use ($lead) {
            $this->engine->handle(new LeadCreated($lead));

            $this->assertSame(1, AutomationRun::where('lead_id', $lead->id)->count(),
                'A stopping rule did not stop the ones after it.');
        });
    }

    public function test_one_broken_action_does_not_abandon_the_others(): void
    {
        $this->rule(['actions' => [
            ['type' => 'does_not_exist'],
            ['type' => 'add_note', 'body' => 'ما زالت تعمل'],
        ]]);

        $lead = $this->lead();

        $this->asTenant($this->t['tenant'], function () use ($lead) {
            $this->engine->handle(new LeadCreated($lead));

            $run = AutomationRun::where('lead_id', $lead->id)->first();

            $this->assertSame('failed', $run->outcome);
            $this->assertFalse($run->applied_actions[0]['ok']);
            $this->assertTrue($run->applied_actions[1]['ok'], 'The second action was abandoned.');
        });
    }

    /** A rep's work is never taken away by a rule. */
    public function test_assignment_never_overrides_a_lead_someone_already_owns(): void
    {
        $rep = $this->makeUser($this->t['tenant']);
        $this->rule(['actions' => [['type' => 'assign', 'strategy' => 'round_robin']]]);

        $lead = $this->lead();
        $lead->forceFill(['assigned_to' => $rep->id])->saveQuietly();

        $this->asTenant($this->t['tenant'], function () use ($lead, $rep) {
            $this->engine->handle(new LeadCreated($lead));

            $this->assertSame($rep->id, $lead->fresh()->assigned_to);
        });
    }

    /** A rule firing on every reply must not bury the rep in duplicates. */
    public function test_a_second_follow_up_is_not_stacked_on_a_pending_one(): void
    {
        $this->rule();
        $lead = $this->lead();

        $this->asTenant($this->t['tenant'], function () use ($lead) {
            $this->engine->handle(new LeadCreated($lead));
            $this->engine->handle(new LeadCreated($lead));

            $this->assertSame(1, FollowUp::where('lead_id', $lead->id)->count());
        });
    }

    /** Another tenant's rules must never see this lead. */
    public function test_rules_do_not_cross_tenants(): void
    {
        $other = $this->provisionTenant('Someone Else');

        $this->asTenant($other['tenant'], fn () => AutomationRule::create([
            'name' => 'Other tenant rule',
            'trigger' => 'lead.created',
            'conditions' => [],
            'actions' => [['type' => 'add_note', 'body' => 'should never appear']],
        ]));

        $lead = $this->lead();

        $this->asTenant($this->t['tenant'], function () use ($lead) {
            $this->engine->handle(new LeadCreated($lead));

            $this->assertSame(0, AutomationRun::where('lead_id', $lead->id)->count());
        });
    }

    public function test_a_disabled_rule_does_not_run(): void
    {
        $this->rule(['is_enabled' => false]);
        $lead = $this->lead();

        $this->asTenant($this->t['tenant'], function () use ($lead) {
            $this->engine->handle(new LeadCreated($lead));

            $this->assertSame(0, AutomationRun::where('lead_id', $lead->id)->count());
        });
    }
}
