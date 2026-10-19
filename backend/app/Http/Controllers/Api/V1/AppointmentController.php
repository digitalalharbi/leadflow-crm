<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Events\LeadSignal;
use App\Events\LeadStageChanged;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Lead;
use App\Services\Leads\ActivityLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Meetings.
 *
 * Booking one moves the lead to the appointment stage by itself. Asking a rep
 * to record the meeting and then separately drag the card is exactly the manual
 * step this product exists to remove — and it is the step people forget, which
 * is how a pipeline ends up disagreeing with the calendar.
 */
class AppointmentController extends Controller
{
    public function __construct(private ActivityLogger $activities) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Lead::class);

        $appointments = Appointment::query()
            ->with(['lead:id,uuid,full_name,phone,status', 'assignee:id,uuid,name'])
            ->when($request->filled('lead_id'), fn ($q) => $q->whereHas('lead',
                fn ($l) => $l->where('uuid', $request->input('lead_id'))))
            ->when($request->input('bucket') === 'upcoming', fn ($q) => $q->upcoming())
            ->when($request->input('bucket') === 'awaiting_outcome', fn ($q) => $q->awaitingOutcome())
            // A rep sees their own meetings unless they are allowed all leads.
            ->when(! $request->user()->seesAllLeads(),
                fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->orderBy('scheduled_at')
            ->paginate(min((int) $request->input('per_page', 25), 100));

        return ApiResponse::paginated($appointments, AppointmentResource::class);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'lead_id' => ['required', 'string'],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $lead = Lead::where('uuid', $data['lead_id'])->firstOrFail();
        $this->authorize('update', $lead);

        $appointment = Appointment::create([
            'lead_id' => $lead->id,
            'assigned_to' => $lead->assigned_to ?? $request->user()->id,
            'created_by' => $request->user()->id,
            'scheduled_at' => $data['scheduled_at'],
            'duration_minutes' => $data['duration_minutes'] ?? 60,
            'location' => $data['location'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->activities->log($lead, ActivityType::AppointmentCreated, $request->user(),
            'موعد في '.$appointment->scheduled_at->toDayDateTimeString(),
            ['appointment_id' => $appointment->uuid]);

        $this->advanceToAppointment($lead, $request);

        return ApiResponse::created([
            'appointment' => new AppointmentResource($appointment->fresh()->load('lead', 'assignee')),
        ]);
    }

    public function update(Request $request, string $uuid)
    {
        $appointment = Appointment::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $appointment->lead);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['scheduled', 'completed', 'cancelled', 'no_show'])],
            'scheduled_at' => ['sometimes', 'date'],
            'duration_minutes' => ['sometimes', 'integer', 'min:5', 'max:480'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'outcome_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $appointment->update($data);

        /*
         * Set after the mass update and deliberately not fillable: when a
         * meeting completed is the server's fact, not the caller's. Leaving it
         * mass-assignable would let a client backdate it and quietly corrupt
         * every response-time figure built on top.
         */
        if (($data['status'] ?? null) === 'completed' && ! $appointment->completed_at) {
            $appointment->forceFill(['completed_at' => now()])->save();
        }

        return ApiResponse::success([
            'appointment' => new AppointmentResource($appointment->fresh()->load('lead', 'assignee')),
        ]);
    }

    /**
     * Booking a meeting is progress, so the stage follows — but only forwards.
     * A lead already won must not be dragged back because someone scheduled a
     * handover call.
     */
    private function advanceToAppointment(Lead $lead, Request $request): void
    {
        // A booked visit is a signal of its own, whether or not the stage moves.
        LeadSignal::dispatch($lead, 'lead.appointment_created', [], $request->user());

        $from = $lead->status;

        // Forwards only, by pipeline position. That one comparison also covers
        // the states that are exits rather than stages — a lead marked invalid
        // sits past this point and must not be resurrected by someone booking
        // a call.
        if ($from->position() >= LeadStatus::Appointment->position()) {
            return;
        }

        $lead->forceFill(['status' => LeadStatus::Appointment])->save();

        $this->activities->log($lead, ActivityType::StatusChanged, $request->user(),
            "من {$from->value} إلى appointment", ['from' => $from->value, 'to' => 'appointment']);

        LeadStageChanged::dispatch($lead, $from, LeadStatus::Appointment, $request->user(),
            ['by' => 'appointment_booked']);
    }
}
