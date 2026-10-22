<?php

namespace App\Domain\Leads;

use App\Enums\LeadStatus;
use App\Events\LeadLost;
use App\Events\LeadQualified;
use App\Events\LeadStageChanged;
use App\Events\LeadWon;
use App\Models\Lead;
use App\Models\User;

/**
 * Says out loud that a lead moved, whichever door it moved through.
 *
 * Rules, the conversion feedback to the ad platforms and the live board all
 * listen for these events. They used to be announced only when a rep recorded
 * an outcome on the work screen — so a deal won by dragging a card on the
 * pipeline, or by pressing the stage on the lead page, was never reported to
 * Meta as a sale and never ran the "won" rules. One announcer, every door.
 */
class StageAnnouncer
{
    public function announce(Lead $lead, ?LeadStatus $from, ?User $actor, array $context = []): void
    {
        $to = $lead->status;

        if ($from !== null && $from !== $to) {
            LeadStageChanged::dispatch($lead, $from, $to, $actor, $context);
        }

        if ($from === $to) {
            return;
        }

        match ($to) {
            LeadStatus::Won => LeadWon::dispatch($lead, $actor, $context),
            LeadStatus::Lost, LeadStatus::Invalid => LeadLost::dispatch($lead, $actor, $context),
            LeadStatus::Qualified => LeadQualified::dispatch($lead, $actor, $context),
            default => null,
        };
    }

    /**
     * A lead that became qualified through its answers rather than its stage —
     * the questions were answered, the score crossed the line. The same event,
     * so the hand-off and the platform feedback follow either way.
     */
    public function qualifiedByAnswers(Lead $lead, ?string $before, ?User $actor): void
    {
        $after = $lead->qualification_status?->value;

        if ($after === 'qualified' && $before !== 'qualified' && $lead->status !== LeadStatus::Qualified) {
            LeadQualified::dispatch($lead, $actor, ['via' => 'answers']);
        }
    }
}
