<?php

namespace App\Events;

use App\Models\Lead;
use App\Models\User;

/**
 * The rep pressed a button in "اعمل الآن" and said what happened.
 *
 * Every other lead event marks a change of state. This one marks an action,
 * which is not the same thing and is why it was missing: an outcome that moves
 * the lead forward already announced itself as a stage change, but "no answer"
 * and "call later" move nothing. They were therefore invisible to the
 * automation engine — and they are the two most common outcomes on a call list.
 *
 * Without this, "after two unanswered calls, send a WhatsApp" could not be
 * written at all, which is most of what a follow-up sequence is for.
 */
class LeadOutcomeRecorded extends LeadEvent
{
    public function __construct(
        Lead $lead,
        public readonly string $outcome,
        ?User $actor = null,
        array $context = [],
    ) {
        parent::__construct($lead, $actor, array_merge($context, ['outcome' => $outcome]));
    }

    public function name(): string
    {
        return 'lead.outcome_recorded';
    }
}
