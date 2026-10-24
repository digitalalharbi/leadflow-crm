<?php

namespace App\Domain\Visits;

use App\Domain\Leads\OutcomeRecorder;
use App\Domain\Tracking\EventRecorder;
use App\Domain\Tracking\EventType;
use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Events\LeadSignal;
use App\Events\LeadStageChanged;
use App\Models\Appointment;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\ImportantNotification;
use App\Services\Leads\ActivityLogger;
use Illuminate\Validation\ValidationException;

/**
 * The life of a visit: asked for → confirmed → (moved) → done or missed → what came of it.
 *
 * Every step is one method that checks the move is allowed, stamps the moment, writes the
 * lead's story and does what must follow — the stage, the follow-up for someone who did not
 * come, the reservation for someone who wants to hold a unit. A visit that happened and has no
 * outcome is the thing this exists to prevent: it is either a sale nobody recorded or a customer
 * nobody called back.
 *
 *   requested   →  confirmed · cancelled
 *   confirmed   →  rescheduled · completed · no_show · cancelled
 *   rescheduled →  rescheduled · completed · no_show · cancelled
 *   no_show     →  rescheduled · cancelled
 *   completed · cancelled   end
 */
class VisitWorkflow
{
    private const MOVES = [
        Appointment::REQUESTED => [Appointment::CONFIRMED, Appointment::CANCELLED],
        Appointment::CONFIRMED => [Appointment::RESCHEDULED, Appointment::COMPLETED, Appointment::NO_SHOW, Appointment::CANCELLED],
        Appointment::RESCHEDULED => [Appointment::RESCHEDULED, Appointment::COMPLETED, Appointment::NO_SHOW, Appointment::CANCELLED],
        Appointment::NO_SHOW => [Appointment::RESCHEDULED, Appointment::CANCELLED],
        Appointment::COMPLETED => [],
        Appointment::CANCELLED => [],
    ];

    /** What a rep's word about a finished visit does to the lead — the same presses as the work queue. */
    private const OUTCOME_TO_RESULT = ['interested' => 'interested', 'follow_up' => 'call_later', 'reservation' => 'reservation', 'lost' => 'lost'];

    public function __construct(private ActivityLogger $activities, private OutcomeRecorder $outcomes) {}

    /** The moves open to a visit now, for the screen to offer only those. */
    public static function movesFrom(string $status): array
    {
        return self::MOVES[$status === 'scheduled' ? Appointment::CONFIRMED : $status] ?? [];
    }

    /**
     * The customer asked to come and see it. One open request per lead: asking twice is still
     * asking once, and a second row would give the sales floor two things to confirm.
     */
    public function request(Lead $lead, ?User $actor = null, string $source = 'customer', array $attributes = []): Appointment
    {
        $open = Appointment::where('lead_id', $lead->id)->whereIn('status', Appointment::LIVE)->latest('id')->first();

        if ($open) {
            return $open;
        }

        $visit = Appointment::create($attributes + [
            'lead_id' => $lead->id,
            'project_id' => $lead->project_id,
            'property_id' => $lead->interested_property_id,
            'assigned_to' => $lead->assigned_to,
            'created_by' => $actor?->id,
            'scheduled_at' => null,
            'status' => Appointment::REQUESTED,
            'source' => $source,
        ]);
        $visit->forceFill(['requested_at' => now()])->save();

        $this->activities->log($lead, ActivityType::VisitUpdated, $actor, null, ['appointment_id' => $visit->uuid, 'to' => Appointment::REQUESTED, 'source' => $source]);
        app(EventRecorder::class)->outcome($lead, EventType::AppointmentRequest, ['appointment' => $visit->uuid], $visit->uuid);

        return $visit;
    }

    /** A rep books a visit. If the customer had already asked for one, that request is the one that gets its time. */
    public function book(Lead $lead, User $actor, \DateTimeInterface $at, array $attributes = []): Appointment
    {
        $requested = Appointment::where('lead_id', $lead->id)->where('status', Appointment::REQUESTED)->latest('id')->first();

        if ($requested) {
            return $this->transition($requested, Appointment::CONFIRMED, $actor, ['scheduled_at' => $at] + $attributes);
        }

        $visit = Appointment::create($attributes + [
            'lead_id' => $lead->id,
            'project_id' => $lead->project_id,
            'property_id' => $lead->interested_property_id,
            'assigned_to' => $lead->assigned_to ?? $actor->id,
            'created_by' => $actor->id,
            'scheduled_at' => $at,
            'status' => Appointment::CONFIRMED,
            'source' => 'rep',
        ]);
        $visit->forceFill(['confirmed_at' => now()])->save();

        $this->activities->log($lead, ActivityType::AppointmentCreated, $actor,
            'موعد في '.$visit->scheduled_at->toDayDateTimeString(), ['appointment_id' => $visit->uuid]);
        app(EventRecorder::class)->outcome($lead, EventType::AppointmentRequest, ['appointment' => $visit->uuid, 'at' => $visit->scheduled_at->toIso8601String()], $visit->uuid);
        $this->advanceStage($lead, $actor, $visit->uuid);

        return $visit;
    }

    /**
     * @param  array{scheduled_at?:mixed, reason?:?string, location?:?string}  $data
     */
    public function transition(Appointment $visit, string $to, ?User $actor, array $data = []): Appointment
    {
        $from = $visit->status === 'scheduled' ? Appointment::CONFIRMED : $visit->status;

        if (! in_array($to, self::MOVES[$from] ?? [], true)) {
            throw ValidationException::withMessages(['status' => ["A {$from} visit cannot become {$to}."]]);
        }

        // Confirming or moving a visit needs a time; nothing else does.
        if (in_array($to, [Appointment::CONFIRMED, Appointment::RESCHEDULED], true) && empty($data['scheduled_at'])) {
            throw ValidationException::withMessages(['scheduled_at' => ['A time is needed.']]);
        }

        $stamp = ['status' => $to];

        match ($to) {
            Appointment::CONFIRMED => $stamp += ['scheduled_at' => $data['scheduled_at'], 'confirmed_at' => now()],
            Appointment::RESCHEDULED => $stamp += ['scheduled_at' => $data['scheduled_at'], 'rescheduled_count' => $visit->rescheduled_count + 1, 'reminded_at' => null],
            Appointment::COMPLETED => $stamp += ['completed_at' => now()],
            Appointment::NO_SHOW => $stamp += ['no_show_at' => now()],
            Appointment::CANCELLED => $stamp += ['cancelled_at' => now()],
        };

        if (! empty($data['location'])) {
            $stamp['location'] = $data['location'];
        }

        if (! empty($data['reason'])) {
            $stamp['outcome_note'] = $data['reason'];
        }

        $visit->forceFill($stamp)->save();
        $lead = $visit->lead;

        $this->activities->log($lead, ActivityType::VisitUpdated, $actor, $data['reason'] ?? null, [
            'appointment_id' => $visit->uuid, 'from' => $from, 'to' => $to, 'at' => $visit->scheduled_at?->toIso8601String(),
        ]);

        match ($to) {
            Appointment::CONFIRMED => $this->confirmed($visit, $lead, $actor),
            Appointment::COMPLETED => app(EventRecorder::class)->outcome($lead, EventType::SiteVisit, ['appointment' => $visit->uuid], $visit->uuid),
            Appointment::NO_SHOW => $this->missed($visit, $lead, $actor),
            default => null,
        };

        return $visit->refresh();
    }

    /**
     * What came of a visit. A visit nobody marked done is done by being given a result: saying
     * "interested" about a Tuesday viewing is saying it happened.
     */
    public function outcome(Appointment $visit, string $outcome, User $actor, ?string $note = null): Appointment
    {
        if (! in_array($outcome, Appointment::OUTCOMES, true)) {
            throw ValidationException::withMessages(['outcome' => ['Unknown outcome.']]);
        }

        if (in_array($visit->status, [Appointment::CANCELLED, Appointment::REQUESTED], true)) {
            throw ValidationException::withMessages(['status' => ['This visit did not happen.']]);
        }

        if (! in_array($visit->status, [Appointment::COMPLETED, Appointment::NO_SHOW], true)) {
            $visit = $this->transition($visit, Appointment::COMPLETED, $actor);
        }

        if ($visit->outcome !== null) {
            return $visit;
        }

        $visit->forceFill(['outcome' => $outcome, 'outcome_at' => now(), 'outcome_note' => $note ?? $visit->outcome_note])->save();
        $lead = $visit->lead;

        // The unit they saw is the unit they are asking about.
        if ($visit->property_id && $lead->interested_property_id !== $visit->property_id) {
            $lead->forceFill(['interested_property_id' => $visit->property_id])->saveQuietly();
        }

        $this->outcomes->record($lead, self::OUTCOME_TO_RESULT[$outcome], $actor, $note, 'visit');

        return $visit->refresh();
    }

    private function confirmed(Appointment $visit, Lead $lead, ?User $actor): void
    {
        $this->advanceStage($lead, $actor, $visit->uuid);
    }

    /** They did not come: somebody calls them in four hours, and the owner is told. */
    private function missed(Appointment $visit, Lead $lead, ?User $actor): void
    {
        $owner = $lead->assigned_to ? User::find($lead->assigned_to) : null;

        if (! FollowUp::where('lead_id', $lead->id)->where('status', FollowUp::PENDING)->exists()) {
            FollowUp::create([
                'lead_id' => $lead->id,
                'assigned_to' => $lead->assigned_to ?? $actor?->id,
                'created_by' => $actor?->id,
                'due_at' => now()->addHours(4),
                'note' => 'لم يحضر الزيارة',
                'status' => FollowUp::PENDING,
            ]);
        }

        $owner?->notify(new ImportantNotification(
            'visit_no_show', 'عميل لم يحضر الزيارة', "{$lead->full_name} لم يحضر موعده — تواصل معه لإعادة الجدولة.", $lead->uuid, null, 'warning',
        ));
    }

    /** A booked visit is progress, so the stage follows — forwards only. */
    private function advanceStage(Lead $lead, ?User $actor, ?string $appointment = null): void
    {
        LeadSignal::dispatch($lead, 'lead.appointment_created', array_filter(['appointment' => $appointment]), $actor);

        $from = $lead->status;

        if ($from->position() >= LeadStatus::Appointment->position()) {
            return;
        }

        $lead->forceFill(['status' => LeadStatus::Appointment])->save();

        $this->activities->log($lead, ActivityType::StatusChanged, $actor,
            "من {$from->value} إلى appointment", ['from' => $from->value, 'to' => 'appointment']);

        LeadStageChanged::dispatch($lead, $from, LeadStatus::Appointment, $actor, ['by' => 'appointment_booked']);
    }
}
