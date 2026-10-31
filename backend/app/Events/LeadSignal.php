<?php

namespace App\Events;

use App\Models\Lead;
use App\Models\User;

/**
 * Something happened to a lead that a rule may want to act on — an email was
 * opened, the brochure was opened, a call ended, the customer went quiet — and
 * that needs no class of its own. The name is the trigger a rule listens for.
 */
class LeadSignal extends LeadEvent
{
    public const NAMES = [
        'lead.email_opened', 'lead.email_clicked', 'lead.file_opened', 'lead.no_reply',
        'lead.score_changed', 'lead.appointment_created', 'lead.call_completed',
        'lead.re_engaged', 'lead.sla_breached', 'lead.interest_changed', 'lead.reservation_created',
    ];

    public function __construct(Lead $lead, public readonly string $signal, array $context = [], ?User $actor = null)
    {
        parent::__construct($lead, $actor, $context);
    }

    public function name(): string
    {
        return $this->signal;
    }
}
