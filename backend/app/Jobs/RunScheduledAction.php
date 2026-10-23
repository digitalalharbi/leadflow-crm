<?php

namespace App\Jobs;

use App\Domain\Automation\ActionExecutor;
use App\Domain\Automation\ActionLog;
use App\Domain\Automation\FollowUpPlanner;
use App\Domain\Automation\HumanTakeover;
use App\Domain\Notifications\ImportantAlerts;
use App\Enums\MessageDirection;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\ScheduledAction;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs what a rule asked for two days ago.
 *
 * The delay is the whole point and also the whole risk: everything the rule
 * decided was true when it decided it, and by now the lead may have replied,
 * been sold, been lost, or asked to be left alone. So this re-reads the world
 * before acting rather than trusting the decision it was handed.
 */
class RunScheduledAction implements ShouldQueue
{
    use Queueable;

    /**
     * Three goes, spaced: a database that blinked is not the end of a wait. What a rule's action itself
     * cannot do is reported by the executor and never thrown, so a retry is only ever for the world.
     * Sending twice is not a risk of retrying — the guardrails refuse the same message inside a day.
     */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public int $scheduledActionId) {}

    /** After the last try: the step is recorded as failed, so the trace stops saying "waiting", and the managers are told. */
    public function failed(\Throwable $e): void
    {
        $scheduled = ScheduledAction::withoutGlobalScopes()->find($this->scheduledActionId);

        if (! $scheduled || $scheduled->status !== ScheduledAction::PENDING) {
            return;
        }

        $why = 'تعذّر تنفيذ هذه الخطوة بعد عدة محاولات — راجع حالة الخادم.';
        $scheduled->resolve(ScheduledAction::FAILED, $why);

        // The exception is for the log; the screen and the managers get words.
        Log::error('Scheduled action failed after retries.', ['scheduled_action' => $scheduled->id, 'error' => $e->getMessage()]);

        $lead = Lead::withoutGlobalScopes()->find($scheduled->lead_id);

        if ($lead?->tenant) {
            app(TenantContext::class)->withTenant($lead->tenant, function () use ($scheduled, $why) {
                app(ActionLog::class)->resolveScheduled($scheduled->id, $scheduled->action ?? [], ['ok' => false, 'description' => $why]);
                ImportantAlerts::automationFailed($scheduled->automation_rule_id, $scheduled->rule_name, $why);
            });
        }
    }

    public function handle(ActionExecutor $actions, TenantContext $tenants): void
    {
        // One worker at a time per step: a catch-up that re-queues a step and the original timer going off
        // must not both run it. Released at the end; a worker that dies lets go when the lock expires.
        $lock = Cache::lock("scheduled-action:{$this->scheduledActionId}", 120);

        if (! $lock->get()) {
            return;
        }

        try {
            $this->run($actions, $tenants);
        } finally {
            $lock->release();
        }
    }

    private function run(ActionExecutor $actions, TenantContext $tenants): void
    {
        /*
         * No tenant scope on the lookups: a queue worker has no request to
         * resolve a tenant from and the scope fails closed outside one, so a
         * scoped query here would find nothing and the action would vanish
         * without a trace. The tenant is established from the lead below.
         */
        $scheduled = ScheduledAction::withoutGlobalScopes()->find($this->scheduledActionId);

        // Cancelled while it waited, or already run. Both are ordinary.
        if (! $scheduled || $scheduled->status !== ScheduledAction::PENDING) {
            return;
        }

        // Moved to a later time by a person: this is the old timer going off early, and the new one will run it.
        if (isset($scheduled->action['moved_to']) && $scheduled->run_at->isFuture()) {
            return;
        }

        $lead = Lead::withoutGlobalScopes()->find($scheduled->lead_id);

        /*
         * `trashed()` as well as missing: lifting the global scopes to get past
         * the tenant scope also lifts the soft-delete one, so a lead the user
         * deleted yesterday would still have been messaged today.
         */
        if (! $lead || $lead->trashed()) {
            $scheduled->resolve(ScheduledAction::CANCELLED, 'العميل لم يعد موجودًا.');

            return;
        }

        /*
         * A closed lead is left alone, whatever the rule said two days ago.
         *
         * Cancelling on closure is handled where the lead closes, but a lead
         * can also be closed by an import, a restore, or a race with this very
         * job — and messaging somebody who has already bought is the complaint
         * that costs a workspace its trust in the tool.
         */
        if ($lead->status?->isClosed()) {
            $scheduled->resolve(ScheduledAction::CANCELLED, 'أُغلق العميل قبل موعد التنفيذ.');
            app(ActionLog::class)->resolveScheduled($scheduled->id, $scheduled->action ?? [], [], 'أُغلق العميل قبل موعد التنفيذ.');

            return;
        }

        /*
         * A step that only makes sense if the customer stayed quiet — a
         * reminder, a nudge, a hand-off for silence — is void once they spoke.
         * Re-checked here as well as cancelled on the reply, because a reply
         * and this job can race.
         */
        if (($scheduled->action['unless'] ?? null) === 'replied') {
            $replied = LeadMessage::withoutGlobalScopes()
                ->where('lead_id', $lead->id)
                ->where('direction', MessageDirection::Inbound->value)
                ->where('created_at', '>=', $scheduled->created_at)
                ->exists();

            if ($replied) {
                $scheduled->resolve(ScheduledAction::CANCELLED, 'ردّ العميل — لم تعد هذه الخطوة لازمة.');
                app(ActionLog::class)->resolveScheduled($scheduled->id, $scheduled->action ?? [], [], 'ردّ العميل — لم تعد هذه الخطوة لازمة.');

                return;
            }
        }

        // A call task for a lead somebody has since called is no longer needed.
        if (($scheduled->action['unless'] ?? null) === 'contacted' && HumanTakeover::contactedSince($lead, $scheduled->created_at)) {
            $scheduled->resolve(ScheduledAction::CANCELLED, 'تواصل المندوب مع العميل — لم تعد هذه الخطوة لازمة.');
            app(ActionLog::class)->resolveScheduled($scheduled->id, $scheduled->action ?? [], [], 'تواصل المندوب مع العميل — لم تعد هذه الخطوة لازمة.');

            return;
        }

        // The follow-up plan looks again before it acts: a booked visit, an unsubscribe, or a person's pause.
        if ($why = app(FollowUpPlanner::class)->gate($lead, $scheduled)) {
            $scheduled->resolve(ScheduledAction::CANCELLED, $why);
            app(ActionLog::class)->resolveScheduled($scheduled->id, $scheduled->action ?? [], [], $why);

            return;
        }

        $tenants->withTenant($lead->tenant, function () use ($actions, $scheduled, $lead) {
            $result = $actions->execute($lead, $scheduled->action ?? []);
            app(ActionLog::class)->resolveScheduled($scheduled->id, $scheduled->action ?? [], $result);

            $scheduled->resolve(
                $result['ok'] ? ScheduledAction::DONE : ScheduledAction::FAILED,
                $result['description'] ?? null,
            );

            // Then it asks again whether the rest is still wanted: continue, escalate, or stop.
            if (! empty($scheduled->action['plan'])) {
                app(FollowUpPlanner::class)->afterStep($lead);
            }
        });
    }
}
