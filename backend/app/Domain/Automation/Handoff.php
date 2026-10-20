<?php

namespace App\Domain\Automation;

use App\Models\Lead;
use App\Models\ScheduledAction;

/**
 * What changes when a lead becomes a person's: the automated messages that
 * would now talk over them stop.
 *
 * Guardrails already refuse to send to a lead a person has; this also cancels
 * the waiting steps themselves, so the trace says "frozen because the rep has
 * them" instead of leaving "waiting" rows that will never run. Appointment
 * messages are kept — a visit confirmation or reminder is service, not
 * nurture, and the rep would want it sent.
 */
class Handoff
{
    /** Purposes that are still welcome after a hand-off. */
    private const ESSENTIAL = ['appointment'];

    private const MESSAGES = ['send_whatsapp', 'send_email', 'share_file', 'ask_qualification', 'start_nurture', 'request_appointment'];

    /** Cancels the waiting automated messages. Returns how many were frozen. */
    public function freeze(Lead $lead): int
    {
        $frozen = 0;

        ScheduledAction::withoutGlobalScopes()->where('lead_id', $lead->id)->where('status', ScheduledAction::PENDING)->get()
            ->each(function (ScheduledAction $step) use (&$frozen) {
                $type = $step->action['type'] ?? '';
                $purpose = $step->action['purpose'] ?? ($type === 'request_appointment' ? 'appointment' : null);

                if (! in_array($type, self::MESSAGES, true) || in_array($purpose, self::ESSENTIAL, true)) {
                    return;
                }

                $why = 'جُمِّدت: العميل مع المندوب الآن.';
                $step->resolve(ScheduledAction::CANCELLED, $why);
                app(ActionLog::class)->resolveScheduled($step->id, $step->action ?? [], [], $why);
                $frozen++;
            });

        return $frozen;
    }
}
