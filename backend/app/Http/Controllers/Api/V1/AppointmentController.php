<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Visits\VisitWorkflow;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Property;
use App\Support\ApiResponse;
use Carbon\Carbon;
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
    public function __construct(private VisitWorkflow $visits) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Lead::class);

        $appointments = Appointment::query()
            ->with(['lead:id,uuid,full_name,phone,status', 'assignee:id,uuid,name', 'project:id,uuid,name', 'property:id,uuid,title'])
            ->when($request->filled('lead_id'), fn ($q) => $q->whereHas('lead',
                fn ($l) => $l->where('uuid', $request->input('lead_id'))))
            ->when($request->input('bucket') === 'upcoming', fn ($q) => $q->upcoming())
            ->when($request->input('bucket') === 'awaiting_outcome', fn ($q) => $q->awaitingOutcome())
            ->when($request->input('bucket') === 'requested', fn ($q) => $q->requested())
            // Done, and nobody has said what came of it.
            ->when($request->input('bucket') === 'needs_result', fn ($q) => $q->where(fn ($w) => $w->where('status', Appointment::COMPLETED)->whereNull('outcome')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            // A rep sees their own meetings unless they are allowed all leads.
            ->when(! $request->user()->seesAllLeads(),
                fn ($q) => $q->where('assigned_to', $request->user()->id))
            // A request has no time yet: it goes first, oldest ask on top.
            ->orderByRaw('scheduled_at is null desc')
            ->orderBy('scheduled_at')
            ->orderBy('requested_at')
            ->paginate(min((int) $request->input('per_page', 25), 100));

        return ApiResponse::paginated($appointments, AppointmentResource::class);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'lead_id' => ['required', 'string'],
            // Left out, the visit is a request: somebody wants to come, nobody has said when.
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'property_id' => ['nullable', 'string'],
        ]);

        $lead = Lead::where('uuid', $data['lead_id'])->firstOrFail();
        $this->authorize('update', $lead);

        $extra = array_filter([
            'duration_minutes' => $data['duration_minutes'] ?? 60,
            'location' => $data['location'] ?? null,
            'notes' => $data['notes'] ?? null,
            'property_id' => ! empty($data['property_id']) ? Property::where('uuid', $data['property_id'])->value('id') : null,
        ], fn ($value) => $value !== null);

        $appointment = empty($data['scheduled_at'])
            ? $this->visits->request($lead, $request->user(), 'rep', $extra)
            : $this->visits->book($lead, $request->user(), Carbon::parse($data['scheduled_at']), $extra);

        return ApiResponse::created([
            'appointment' => new AppointmentResource($appointment->fresh()->load('lead', 'assignee', 'project', 'property')),
        ]);
    }

    public function update(Request $request, string $uuid)
    {
        $appointment = Appointment::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $appointment->lead);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in([...Appointment::STATUSES, 'scheduled'])],
            'scheduled_at' => ['sometimes', 'date'],
            'duration_minutes' => ['sometimes', 'integer', 'min:5', 'max:480'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'outcome_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $to = $data['status'] ?? null;
        $to = $to === 'scheduled' ? Appointment::CONFIRMED : $to;
        unset($data['status']);

        /*
         * A new time on a visit that is already booked is a reschedule, whatever the caller called
         * it: the count and the story must say the visit moved.
         */
        $moved = isset($data['scheduled_at']) && in_array($appointment->status, Appointment::BOOKED, true)
            && Carbon::parse($data['scheduled_at'])->ne($appointment->scheduled_at);

        if ($moved && ($to === null || $to === Appointment::CONFIRMED)) {
            $to = Appointment::RESCHEDULED;
        }

        $plain = array_diff_key($data, ['scheduled_at' => 1]);

        if ($to !== null && $to !== $appointment->status && ! ($to === Appointment::CONFIRMED && $appointment->status === 'scheduled')) {
            $appointment = $this->visits->transition($appointment, $to, $request->user(), $data);
            $plain = array_diff_key($plain, ['location' => 1, 'outcome_note' => 1]);
        } elseif (isset($data['scheduled_at'])) {
            $plain['scheduled_at'] = $data['scheduled_at'];
        }

        $plain && $appointment->update($plain);

        return ApiResponse::success([
            'appointment' => new AppointmentResource($appointment->fresh()->load('lead', 'assignee', 'project', 'property')),
        ]);
    }

    /** The one move a screen button means: confirm, reschedule, cancel, mark done, mark missed. */
    public function transition(Request $request, string $uuid)
    {
        $appointment = Appointment::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $appointment->lead);

        $data = $request->validate([
            'to' => ['required', Rule::in(Appointment::STATUSES)],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        $appointment = $this->visits->transition($appointment, $data['to'], $request->user(), $data);

        return ApiResponse::success(['appointment' => new AppointmentResource($appointment->load('lead', 'assignee', 'project', 'property'))]);
    }

    /** What came of the visit: interested, follow-up, reservation or lost. */
    public function outcome(Request $request, string $uuid)
    {
        $appointment = Appointment::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $appointment->lead);

        $data = $request->validate([
            'outcome' => ['required', Rule::in(Appointment::OUTCOMES)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $appointment = $this->visits->outcome($appointment, $data['outcome'], $request->user(), $data['note'] ?? null);

        return ApiResponse::success(['appointment' => new AppointmentResource($appointment->load('lead', 'assignee', 'project', 'property'))]);
    }
}
