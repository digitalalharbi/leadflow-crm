<?php

namespace Tests\Feature;

use App\Domain\Automation\ActionExecutor;
use App\Enums\LeadStatus;
use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\LostReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A closed lead carries the marks of how it closed, and only those.
 *
 * The status controller set these by hand and got them right; nothing else did.
 * The automation engine's `change_stage` wrote `forceFill(['status' => $to])`
 * and saved, so a rule that moved a lead to won left `won_at` null — and
 * `won_at` is what the velocity chart, the conversion analysis and the revenue
 * attribution all count. A sale closed by a rule was invisible to every report
 * that measures sales.
 *
 * These tests exercise the paths that bypass the controller, because the
 * controller was never the one getting it wrong.
 */
class LeadClosedStateInvariantTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Invariant Co');
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'عميل',
            'phone_normalized' => '+9665004'.random_int(10000, 99999),
            'status' => 'new',
        ], $attributes)));
    }

    public function test_a_won_lead_always_carries_the_moment_it_was_won(): void
    {
        $this->assertNotNull($this->lead(['status' => 'won'])->fresh()->won_at);
    }

    public function test_a_lost_lead_always_carries_the_moment_it_was_lost(): void
    {
        $this->assertNotNull($this->lead(['status' => 'lost'])->fresh()->lost_at);
    }

    /** `forceFill` is how the automation engine writes, and it must not escape. */
    public function test_force_filling_a_status_still_stamps_the_closure(): void
    {
        $lead = $this->lead();

        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['status' => 'won'])->save());

        $this->assertNotNull($lead->fresh()->won_at);
    }

    /**
     * A lead dragged back out of won still holding `won_at` and its deal value
     * stays in the revenue its campaign is credited with — visibly gone from
     * the pipeline and still counted in the return.
     */
    public function test_reopening_a_won_lead_clears_the_win_and_its_value(): void
    {
        $lead = $this->lead(['status' => 'won']);
        $lead->forceFill(['deal_value_minor' => 500000])->save();
        $this->assertNotNull($lead->fresh()->won_at);

        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['status' => 'negotiation'])->save());

        $this->assertNull($lead->fresh()->won_at);
        $this->assertNull($lead->fresh()->deal_value_minor);
    }

    public function test_reopening_a_lost_lead_clears_the_loss_and_its_reason(): void
    {
        $reason = $this->asTenant($this->t['tenant'], fn () => LostReason::first());
        $lead = $this->lead(['status' => 'lost', 'lost_reason_id' => $reason?->id, 'lost_note' => 'سعر']);

        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['status' => 'contacted'])->save());

        $fresh = $lead->fresh();
        $this->assertNull($fresh->lost_at);
        $this->assertNull($fresh->lost_reason_id);
        $this->assertNull($fresh->lost_note);
    }

    /** A lead cannot be both. Winning one that was lost erases the loss. */
    public function test_a_lead_cannot_be_won_and_lost_at_once(): void
    {
        $reason = $this->asTenant($this->t['tenant'], fn () => LostReason::first());
        $lead = $this->lead(['status' => 'lost', 'lost_reason_id' => $reason?->id]);

        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['status' => 'won'])->save());

        $fresh = $lead->fresh();
        $this->assertNotNull($fresh->won_at);
        $this->assertNull($fresh->lost_at);
        $this->assertNull($fresh->lost_reason_id);
    }

    /** The hole this was written for. */
    public function test_a_rule_that_wins_a_lead_stamps_the_win(): void
    {
        $lead = $this->lead();

        $result = $this->asTenant($this->t['tenant'], fn () => app(ActionExecutor::class)
            ->execute($lead, ['type' => 'change_stage', 'status' => 'won']));

        $this->assertTrue($result['ok']);
        $this->assertSame(LeadStatus::Won, $lead->fresh()->status);
        // Without this the sale is invisible to every report that counts sales.
        $this->assertNotNull($lead->fresh()->won_at);
    }

    /**
     * The model backfills a timestamp because `now()` is an approximation it
     * can defend. A lost reason is not: it is the thing the lost-reasons report
     * is made of, and a value invented here would be indistinguishable from one
     * a rep chose. So the rule fails loudly instead.
     */
    public function test_a_rule_cannot_lose_a_lead_without_saying_why(): void
    {
        $lead = $this->lead();

        $result = $this->asTenant($this->t['tenant'], fn () => app(ActionExecutor::class)
            ->execute($lead, ['type' => 'change_stage', 'status' => 'lost']));

        $this->assertFalse($result['ok']);
        $this->assertSame(LeadStatus::New, $lead->fresh()->status);
    }

    public function test_a_rule_may_lose_a_lead_when_it_names_the_reason(): void
    {
        $lead = $this->lead();
        $reason = $this->asTenant($this->t['tenant'], fn () => LostReason::first());

        $result = $this->asTenant($this->t['tenant'], fn () => app(ActionExecutor::class)
            ->execute($lead, ['type' => 'change_stage', 'status' => 'lost', 'lost_reason_id' => $reason->uuid]));

        $this->assertTrue($result['ok']);

        $fresh = $lead->fresh();
        $this->assertSame(LeadStatus::Lost, $fresh->status);
        $this->assertSame($reason->id, $fresh->lost_reason_id);
        $this->assertNotNull($fresh->lost_at);
    }

    public function test_a_rule_naming_a_reason_that_does_not_exist_is_refused(): void
    {
        $lead = $this->lead();

        $result = $this->asTenant($this->t['tenant'], fn () => app(ActionExecutor::class)
            ->execute($lead, [
                'type' => 'change_stage',
                'status' => 'lost',
                'lost_reason_id' => '11111111-2222-3333-4444-555555555555',
            ]));

        $this->assertFalse($result['ok']);
        $this->assertSame(LeadStatus::New, $lead->fresh()->status);
    }

    /** An open lead carries no closing marks at all. */
    public function test_an_open_lead_carries_no_closure(): void
    {
        $lead = $this->lead(['status' => 'contacted']);
        $fresh = $lead->fresh();

        $this->assertNull($fresh->won_at);
        $this->assertNull($fresh->lost_at);
        $this->assertNull($fresh->lost_reason_id);
    }

    /**
     * The rule has to be refusable where it is written, not where it runs.
     *
     * The executor will not close a lead as lost without a reason — that is the
     * invariant. But `validate()` returns only the keys it has rules for, so a
     * reason sent by the editor was stripped on the way in, and the rule failed
     * on every run into a trace nobody reads. A rule that cannot work must not
     * save.
     */
    public function test_a_rule_that_closes_a_lead_as_lost_must_name_the_reason(): void
    {
        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/automation/rules', [
                'name' => 'إغلاق تلقائي',
                'trigger' => 'lead.created',
                'conditions' => [],
                'actions' => [['type' => 'change_stage', 'status' => 'lost']],
            ])
            ->assertStatus(422);
    }

    public function test_a_rule_keeps_the_lost_reason_it_was_given(): void
    {
        $reason = $this->asTenant($this->t['tenant'], fn () => LostReason::firstOrFail());

        $uuid = $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/automation/rules', [
                'name' => 'إغلاق تلقائي',
                'trigger' => 'lead.created',
                'conditions' => [],
                'actions' => [[
                    'type' => 'change_stage',
                    'status' => 'lost',
                    'lost_reason_id' => $reason->uuid,
                ]],
            ])
            ->assertCreated()
            ->json('data.rule.id');

        $saved = AutomationRule::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();

        $this->assertSame($reason->uuid, $saved->actions[0]['lost_reason_id'] ?? null);
    }
}
