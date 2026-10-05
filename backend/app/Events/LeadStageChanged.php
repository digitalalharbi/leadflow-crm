<?php

namespace App\Events;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;

/**
 * Carries both ends of the move, because most rules care about the direction:
 * "qualified → appointment" deserves a different response from "appointment →
 * qualified", and a rule that only sees the destination cannot tell them apart.
 */
class LeadStageChanged extends LeadEvent
{
    public function __construct(
        Lead $lead,
        public readonly LeadStatus $from,
        public readonly LeadStatus $to,
        ?User $actor = null,
        array $context = [],
    ) {
        parent::__construct($lead, $actor, $context);
    }

    public function name(): string
    {
        return 'lead.stage_changed';
    }
}
