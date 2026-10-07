<?php

namespace App\Domain\Leads;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Events\LeadLost;
use App\Events\LeadOutcomeRecorded;
use App\Events\LeadQualified;
use App\Events\LeadStageChanged;
use App\Events\LeadWon;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * One rep keystroke, everything that must follow it.
 *
 * The product's whole claim is that a salesperson should press one thing and
 * the system should do the rest: record what happened, move the stage, book the
 * next step, let the automation rules see it. Before this, a rep who spoke to a
 * lead had to log a contact, then change the status, then remember to create a
 * follow-up — three screens, and the third one is the one people skip. A lead
 * with no next step is a lead nobody calls again.
 *
 * Each outcome below therefore answers three questions at once: what happened,
 * where the lead now sits, and when somebody touches it next. The answers are
 * in one table so they can be read as a policy rather than reconstructed from
 * six branches.
 */
class OutcomeRecorder
{
    /**
     * The outcomes a rep can record, and what each one means.
     *
     * `stage` is a floor, not an assignment: an outcome never drags a lead
     * backwards. Telling the system "interested" about a lead already at
     * negotiation must not demote it to qualified — that would lose work
     * somebody has already done, silently, as a reward for using the button.
     *
     * `followUpHours` of null means no next step is implied; `0` means the
     * lead is finished and any pending step should be closed.
     */
    private const OUTCOMES = [
        'interested' => [
            'stage' => LeadStatus::Qualified,
            'activity' => ActivityType::QualificationUpdated,
            'followUpHours' => 48,
            'label' => 'مهتم',
        ],
        'call_later' => [
            'stage' => LeadStatus::Contacted,
            'activity' => ActivityType::Called,
            'followUpHours' => 24,
            'label' => 'أعاود الاتصال',
        ],
        'no_answer' => [
            'stage' => LeadStatus::Contacted,
            'activity' => ActivityType::NoAnswer,
            'followUpHours' => 3,
            'label' => 'لم يرد',
        ],
        'meeting_booked' => [
            'stage' => LeadStatus::Appointment,
            'activity' => ActivityType::AppointmentCreated,
            'followUpHours' => null,
            'label' => 'تم حجز موعد',
        ],
        'won' => [
            'stage' => LeadStatus::Won,
            'activity' => ActivityType::Won,
            'followUpHours' => 0,
            'label' => 'تم البيع',
        ],
        'lost' => [
            'stage' => LeadStatus::Lost,
            'activity' => ActivityType::Lost,
            'followUpHours' => 0,
            'label' => 'خسارة',
        ],
        'not_interested' => [
            'stage' => LeadStatus::Lost,
            'activity' => ActivityType::Lost,
            'followUpHours' => 0,
            'label' => 'غير مهتم',
        ],
        'invalid' => [
            'stage' => LeadStatus::Invalid,
            'activity' => ActivityType::StatusChanged,
            'followUpHours' => 0,
            'label' => 'رقم خاطئ',
        ],
    ];

    public function __construct(private readonly ActivityLogger $activities) {}

    /** @return array<int,string> */
    public static function available(): array
    {
        return array_keys(self::OUTCOMES);
    }

    public static function knows(string $outcome): bool
    {
        return isset(self::OUTCOMES[$outcome]);
    }

    /**
     * @return array{lead: Lead, stage_changed: bool, follow_up: ?FollowUp, summary: string}
     */
    public function record(Lead $lead, string $outcome, User $actor, ?string $note = null): array
    {
        $spec = self::OUTCOMES[$outcome];
        $from = $lead->status;

        return DB::transaction(function () use ($lead, $spec, $outcome, $actor, $note, $from) {
            $this->activities->log($lead, $spec['activity'], $actor,
                $note ?: $spec['label'],
                ['outcome' => $outcome, 'via' => 'work_queue']);

            /*
             * Recording an outcome is the act of having worked the lead.
             *
             * `last_contact_at` is what the product means by "when did anybody
             * last do anything with this lead", and the status endpoint, the
             * follow-up screen and WhatsApp all set it. The work queue — where
             * a rep spends the whole day — did not, so a lead called every
             * morning for a week read as untouched since the day it arrived.
             *
             * Three things read that column and all three were wrong about the
             * same leads: the data-quality screen listing stale leads, the
             * re-engagement filter, and `minutes_since_last_contact` — which a
             * rule uses to escalate a lead nobody has called, and so escalated
             * the ones being called hardest.
             *
             * An unanswered call counts. The product already treats one as
             * contact: "no answer" moves the lead to `contacted`, and that
             * status is in `impliesContactWasMade`.
             */
            $lead->last_contact_at = now();

            $stageChanged = $this->advance($lead, $spec['stage']);
            $followUp = $this->scheduleNextStep($lead, $spec['followUpHours'], $actor, $spec['label']);

            $lead->refresh();

            /*
             * Announced whether or not the stage moved.
             *
             * `announce()` only fires when the lead advances, so "no answer"
             * and "call later" — the two most common outcomes on a call list —
             * reached the automation engine as nothing at all. A workspace
             * could not write "after two unanswered calls, send a WhatsApp",
             * which is most of what a follow-up sequence is for.
             */
            LeadOutcomeRecorded::dispatch($lead, $outcome, $actor);

            if ($stageChanged) {
                $this->announce($lead, $from, $actor, $outcome);
            }

            return [
                'lead' => $lead,
                'stage_changed' => $stageChanged,
                'follow_up' => $followUp,
                'summary' => $this->summarise($spec, $stageChanged, $followUp),
            ];
        });
    }

    /** Moves the lead forward only. Never backwards — see OUTCOMES. */
    private function advance(Lead $lead, LeadStatus $floor): bool
    {
        // A closed lead is the one exception: "won" and "lost" are decisions,
        // not progress, so they apply even to a lead further along the board.
        $isDecision = $floor->isClosed();

        if (! $isDecision && $lead->status->position() >= $floor->position()) {
            // Saved anyway: the outcome touched the lead even when it moved no
            // stage, and "no answer" on an already-contacted lead is the most
            // common outcome there is.
            $lead->save();

            return false;
        }

        if ($lead->status === $floor) {
            $lead->save();

            return false;
        }

        $lead->update(['status' => $floor]);

        return true;
    }

    private function scheduleNextStep(Lead $lead, ?int $hours, User $actor, string $label): ?FollowUp
    {
        if ($hours === null) {
            return null;
        }

        // A finished lead keeps no open step. Chasing a customer who already
        // bought is the complaint that costs a workspace its trust in the tool.
        if ($hours === 0) {
            FollowUp::where('lead_id', $lead->id)->where('status', FollowUp::PENDING)
                ->update(['status' => FollowUp::CANCELLED]);

            return null;
        }

        // One open step at a time: stacking a second reminder on a lead that
        // already has one produces two notifications for one piece of work.
        $pending = FollowUp::where('lead_id', $lead->id)->where('status', FollowUp::PENDING)->first();

        if ($pending) {
            return $pending;
        }

        return FollowUp::create([
            'lead_id' => $lead->id,
            'assigned_to' => $lead->assigned_to ?? $actor->id,
            'created_by' => $actor->id,
            'due_at' => now()->addHours($hours),
            'note' => $label,
            'status' => FollowUp::PENDING,
        ]);
    }

    /** Lets the automation rules see what the rep just did. */
    private function announce(Lead $lead, LeadStatus $from, User $actor, string $outcome): void
    {
        LeadStageChanged::dispatch($lead, $from, $lead->status, $actor, ['outcome' => $outcome]);

        match ($lead->status) {
            LeadStatus::Won => LeadWon::dispatch($lead, $actor, ['outcome' => $outcome]),
            LeadStatus::Lost, LeadStatus::Invalid => LeadLost::dispatch($lead, $actor, ['outcome' => $outcome]),
            LeadStatus::Qualified => LeadQualified::dispatch($lead, $actor, ['outcome' => $outcome]),
            default => null,
        };
    }

    private function summarise(array $spec, bool $stageChanged, ?FollowUp $followUp): string
    {
        $parts = [$spec['label']];

        if ($stageChanged) {
            $parts[] = 'المرحلة: '.$spec['stage']->value;
        }

        if ($followUp) {
            $parts[] = 'المتابعة: '.$followUp->due_at->format('Y-m-d H:i');
        }

        return implode(' · ', $parts);
    }
}
