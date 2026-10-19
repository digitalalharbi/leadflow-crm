<?php

namespace App\Jobs;

use App\Domain\Automation\ActionExecutor;
use App\Enums\MessageDirection;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\ScheduledAction;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

    public function __construct(public int $scheduledActionId) {}

    public function handle(ActionExecutor $actions, TenantContext $tenants): void
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

                return;
            }
        }

        $tenants->withTenant($lead->tenant, function () use ($actions, $scheduled, $lead) {
            $result = $actions->execute($lead, $scheduled->action ?? []);

            $scheduled->resolve(
                $result['ok'] ? ScheduledAction::DONE : ScheduledAction::FAILED,
                $result['description'] ?? null,
            );
        });
    }
}
