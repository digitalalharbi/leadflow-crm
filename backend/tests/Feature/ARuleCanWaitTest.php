<?php

namespace Tests\Feature;

use App\Domain\Automation\ActionExecutor;
use App\Domain\Automation\AutomationEngine;
use App\Enums\ActivityType;
use App\Events\LeadCreated;
use App\Jobs\RunScheduledAction;
use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\ScheduledAction;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * "And in two days, send a WhatsApp."
 *
 * Automation was entirely immediate: a rule ran the instant its event arrived
 * and had no way to say "and then, later". That left the most ordinary
 * follow-up sequence in the business unwritable, because nothing in the system
 * ever fires two days after something did not happen.
 *
 * The delay is the whole point and also the whole risk. Everything the rule
 * decided was true when it decided it, and by the time the action runs the lead
 * may have replied, been sold, been lost, or asked to be left alone. Most of
 * what is tested here is that second half.
 */
class ARuleCanWaitTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Patience Co');
        $this->withoutJourney($this->t['tenant']);
        Queue::fake();
    }

    private function rule(array $actions, string $name = 'بعد يومين'): AutomationRule
    {
        return $this->asTenant($this->t['tenant'], fn () => AutomationRule::create([
            'name' => $name,
            'trigger' => 'lead.created',
            'conditions' => [],
            'actions' => $actions,
            'is_enabled' => true,
        ]));
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'عميل',
            'phone_normalized' => '+96650017'.random_int(1000, 9999),
            'status' => 'new',
            'assigned_to' => $this->t['admin']->id,
        ], $attributes)));
    }

    private function fire(Lead $lead): void
    {
        $this->asTenant(
            $this->t['tenant'],
            fn () => app(AutomationEngine::class)->handle(new LeadCreated($lead)),
        );
    }

    private function runNow(ScheduledAction $action): void
    {
        (new RunScheduledAction($action->id))->handle(
            app(ActionExecutor::class),
            app(TenantContext::class),
        );
    }

    private function notes(Lead $lead): int
    {
        return LeadActivity::withoutGlobalScopes()
            ->where('lead_id', $lead->id)
            ->where('type', ActivityType::NoteAdded->value)
            ->count();
    }

    public function test_a_delayed_action_waits_instead_of_running(): void
    {
        $this->rule([['type' => 'add_note', 'body' => 'تابع العميل', 'delay_minutes' => 2880]]);
        $lead = $this->lead();

        $this->fire($lead);

        $this->assertSame(0, $this->notes($lead), 'it must not have happened yet');

        $scheduled = ScheduledAction::withoutGlobalScopes()->where('lead_id', $lead->id)->first();

        $this->assertNotNull($scheduled);
        $this->assertSame(ScheduledAction::PENDING, $scheduled->status);
        $this->assertEqualsWithDelta(2880, now()->diffInMinutes($scheduled->run_at), 1);

        Queue::assertPushed(RunScheduledAction::class);
    }

    /** An action with no delay is unchanged: it happens now. */
    public function test_an_action_without_a_delay_still_runs_immediately(): void
    {
        $this->rule([['type' => 'add_note', 'body' => 'الآن']]);
        $lead = $this->lead();

        $this->fire($lead);

        $this->assertSame(1, $this->notes($lead));
        $this->assertSame(0, ScheduledAction::withoutGlobalScopes()->count());
    }

    /** One rule can do something now and something else later. */
    public function test_a_rule_can_act_now_and_again_later(): void
    {
        $this->rule([
            ['type' => 'add_note', 'body' => 'الآن'],
            ['type' => 'add_note', 'body' => 'بعد يومين', 'delay_minutes' => 2880],
        ]);

        $lead = $this->lead();
        $this->fire($lead);

        $this->assertSame(1, $this->notes($lead));
        $this->assertSame(1, ScheduledAction::withoutGlobalScopes()->pending()->count());
    }

    public function test_when_the_time_comes_it_does_the_thing(): void
    {
        $this->rule([['type' => 'add_note', 'body' => 'تابع العميل', 'delay_minutes' => 60]]);
        $lead = $this->lead();
        $this->fire($lead);

        $scheduled = ScheduledAction::withoutGlobalScopes()->first();
        $this->runNow($scheduled);

        $this->assertSame(1, $this->notes($lead));
        $this->assertSame(ScheduledAction::DONE, $scheduled->fresh()->status);
        $this->assertNotNull($scheduled->fresh()->resolved_at);
    }

    /**
     * The one that matters most. A lead who bought yesterday must not get the
     * message the rule promised the day before — that is the complaint that
     * costs a workspace its trust in the tool.
     */
    public function test_closing_the_lead_cancels_what_was_promised(): void
    {
        $this->rule([['type' => 'add_note', 'body' => 'تابع العميل', 'delay_minutes' => 2880]]);
        $lead = $this->lead();
        $this->fire($lead);

        $this->asTenant($this->t['tenant'], fn () => $lead->update(['status' => 'won']));

        $scheduled = ScheduledAction::withoutGlobalScopes()->first();

        $this->assertSame(ScheduledAction::CANCELLED, $scheduled->status);
        $this->assertNotEmpty($scheduled->outcome, 'the trace has to say why it never happened');
    }

    /** Cancelled is cancelled: a job that fires anyway does nothing. */
    public function test_a_cancelled_action_does_nothing_if_its_job_still_fires(): void
    {
        $this->rule([['type' => 'add_note', 'body' => 'تابع العميل', 'delay_minutes' => 60]]);
        $lead = $this->lead();
        $this->fire($lead);

        $scheduled = ScheduledAction::withoutGlobalScopes()->first();
        $scheduled->resolve(ScheduledAction::CANCELLED, 'بيد المستخدم');

        $this->runNow($scheduled);

        $this->assertSame(0, $this->notes($lead));
    }

    /**
     * Closing can also happen by a path that does not touch the model hook — an
     * import, a restore, a race with this very job — so the job checks too.
     */
    public function test_the_job_checks_again_before_acting(): void
    {
        $this->rule([['type' => 'add_note', 'body' => 'تابع العميل', 'delay_minutes' => 60]]);
        $lead = $this->lead();
        $this->fire($lead);

        $scheduled = ScheduledAction::withoutGlobalScopes()->first();

        /*
         * Straight to the columns, bypassing the hook that would have cancelled
         * it. `first_contact_at` goes too: the database has its own check that a
         * contacted-or-beyond lead carries one, which is the model invariant
         * enforced a second time where nothing can route around it.
         */
        Lead::withoutGlobalScopes()->where('id', $lead->id)->update([
            'status' => 'won',
            'first_contact_at' => now(),
            'won_at' => now(),
        ]);

        $this->runNow($scheduled);

        $this->assertSame(0, $this->notes($lead));
        $this->assertSame(ScheduledAction::CANCELLED, $scheduled->fresh()->status);
    }

    /**
     * A lead the user deleted does not get messaged two days later.
     *
     * Leads are soft-deleted, and lifting the global scopes to get past the
     * tenant scope lifts the soft-delete one with it — so the lookup finds a
     * deleted lead happily. The job checks `trashed()` for exactly that reason.
     */
    public function test_a_deleted_lead_cancels_rather_than_acting(): void
    {
        $this->rule([['type' => 'add_note', 'body' => 'تابع', 'delay_minutes' => 60]]);
        $lead = $this->lead();
        $this->fire($lead);

        $scheduled = ScheduledAction::withoutGlobalScopes()->first();
        $this->asTenant($this->t['tenant'], fn () => $lead->delete());

        $this->runNow($scheduled);

        $this->assertSame(0, $this->notes($lead));
        $this->assertSame(ScheduledAction::CANCELLED, $scheduled->fresh()->status);
    }

    /**
     * The rule may be renamed or deleted while its action waits, and the trace
     * still has to say what asked for it.
     */
    public function test_it_remembers_which_rule_asked_even_if_the_rule_is_gone(): void
    {
        $rule = $this->rule([['type' => 'add_note', 'body' => 'تابع', 'delay_minutes' => 60]], 'قاعدة ستُحذف');
        $lead = $this->lead();
        $this->fire($lead);

        $this->asTenant($this->t['tenant'], fn () => $rule->delete());

        $scheduled = ScheduledAction::withoutGlobalScopes()->first();

        // The name is copied onto the row, so it survives whatever happens to
        // the rule. (The foreign key stays: rules are soft-deleted, so the row
        // it points at is still there — which is why the copy is the thing the
        // trace reads.)
        $this->assertSame('قاعدة ستُحذف', $scheduled->rule_name);
    }

    /** It carries the correlation id, so the trace joins up across two days. */
    public function test_it_can_be_traced_back_to_the_event_that_asked_for_it(): void
    {
        $this->rule([['type' => 'add_note', 'body' => 'تابع', 'delay_minutes' => 60]]);
        $lead = $this->lead();
        $this->fire($lead);

        $this->assertNotNull(ScheduledAction::withoutGlobalScopes()->first()->correlation_id);
    }

    public function test_a_delay_beyond_a_month_is_refused(): void
    {
        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/automation/rules', [
                'name' => 'بعد سنة',
                'trigger' => 'lead.created',
                'conditions' => [],
                'actions' => [['type' => 'add_note', 'body' => 'x', 'delay_minutes' => 60 * 24 * 60]],
            ])
            ->assertStatus(422);
    }

    public function test_a_rule_keeps_the_delay_it_was_given(): void
    {
        $uuid = $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/automation/rules', [
                'name' => 'بعد يومين',
                'trigger' => 'lead.created',
                'conditions' => [],
                'actions' => [['type' => 'add_note', 'body' => 'x', 'delay_minutes' => 2880]],
            ])
            ->assertCreated()
            ->json('data.rule.id');

        $saved = AutomationRule::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();

        $this->assertSame(2880, $saved->actions[0]['delay_minutes'] ?? null);
    }

    public function test_it_never_schedules_across_workspaces(): void
    {
        $this->rule([['type' => 'add_note', 'body' => 'تابع', 'delay_minutes' => 60]]);
        $lead = $this->lead();
        $this->fire($lead);

        $other = $this->provisionTenant('Somebody Else');

        $this->assertSame(0, $this->asTenant(
            $other['tenant'],
            fn () => ScheduledAction::count(),
        ));
    }

    /**
     * A trace that showed only the past would say a rule "applied" on Tuesday
     * and never mention the WhatsApp it arranged for Thursday — which is the
     * one thing somebody reading the screen on Wednesday needs to know.
     */
    public function test_the_trace_shows_what_is_still_coming(): void
    {
        $this->rule([['type' => 'add_note', 'body' => 'تابع', 'delay_minutes' => 2880]], 'قاعدة الانتظار');
        $lead = $this->lead();
        $this->fire($lead);

        $scheduled = $this->actingAs($this->t['admin'])
            ->getJson("/api/v1/leads/{$lead->uuid}/automation-runs")
            ->assertOk()
            ->json('data.scheduled');

        $this->assertCount(1, $scheduled);
        $this->assertSame('قاعدة الانتظار', $scheduled[0]['rule_name']);
        $this->assertSame('add_note', $scheduled[0]['type']);
        $this->assertSame(ScheduledAction::PENDING, $scheduled[0]['status']);
    }

    /**
     * The rule decided on Tuesday; by Wednesday a manager may know something it
     * does not. Without this the only way to stop it was to close the lead,
     * which is a worse thing to do to the pipeline than the message was.
     */
    public function test_a_manager_can_call_off_what_a_rule_promised(): void
    {
        $this->rule([['type' => 'add_note', 'body' => 'تابع', 'delay_minutes' => 2880]]);
        $lead = $this->lead();
        $this->fire($lead);

        $scheduled = ScheduledAction::withoutGlobalScopes()->first();

        $this->actingAs($this->t['admin'])
            ->deleteJson("/api/v1/scheduled-actions/{$scheduled->uuid}")
            ->assertOk();

        $this->assertSame(ScheduledAction::CANCELLED, $scheduled->fresh()->status);

        // And the job firing later does nothing.
        $this->runNow($scheduled->fresh());
        $this->assertSame(0, $this->notes($lead));
    }

    public function test_cancelling_something_already_done_is_refused(): void
    {
        $this->rule([['type' => 'add_note', 'body' => 'تابع', 'delay_minutes' => 60]]);
        $lead = $this->lead();
        $this->fire($lead);

        $scheduled = ScheduledAction::withoutGlobalScopes()->first();
        $this->runNow($scheduled);

        $this->actingAs($this->t['admin'])
            ->deleteJson("/api/v1/scheduled-actions/{$scheduled->uuid}")
            ->assertStatus(422);
    }

    public function test_nobody_can_cancel_an_action_in_another_workspace(): void
    {
        $this->rule([['type' => 'add_note', 'body' => 'تابع', 'delay_minutes' => 60]]);
        $this->fire($this->lead());

        $scheduled = ScheduledAction::withoutGlobalScopes()->first();
        $other = $this->provisionTenant('Somebody Else');

        $this->actingAs($other['admin'])
            ->deleteJson("/api/v1/scheduled-actions/{$scheduled->uuid}")
            ->assertNotFound();

        $this->assertSame(ScheduledAction::PENDING, $scheduled->fresh()->status);
    }
}
