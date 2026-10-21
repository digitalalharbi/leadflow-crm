<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Visits\ReservationService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReservationResource;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Reservation;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** Units held for leads: who, which unit, until when — and the three things that can happen next. */
class ReservationController extends Controller
{
    public function __construct(private ReservationService $reservations) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Lead::class);

        $rows = Reservation::query()
            ->with(['lead:id,uuid,full_name,phone,assigned_to', 'project:id,uuid,name', 'property:id,uuid,title,price_minor,status,transaction_type'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->input('bucket') === 'open', fn ($q) => $q->whereIn('status', Reservation::OPEN))
            // A rep sees the reservations of their own leads unless they are allowed all leads.
            ->when(! $request->user()->seesAllLeads(), fn ($q) => $q->whereHas('lead', fn ($l) => $l->where('assigned_to', $request->user()->id)))
            ->orderByRaw("case status when 'pending' then 0 when 'confirmed' then 1 else 2 end")
            ->orderBy('expires_at')
            ->latest('id')
            ->paginate(min((int) $request->input('per_page', 25), 100));

        return ApiResponse::paginated($rows, ReservationResource::class);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'lead_id' => ['required', 'string'],
            'property_id' => ['nullable', 'string'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $lead = Lead::where('uuid', $data['lead_id'])->firstOrFail();
        $this->authorize('update', $lead);

        $property = ! empty($data['property_id']) ? Property::where('uuid', $data['property_id'])->firstOrFail() : null;

        $reservation = $this->reservations->create($lead, $request->user(), $property, [
            'amount_minor' => isset($data['amount']) ? (int) round($data['amount'] * 100) : null,
            'expires_at' => $data['expires_at'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return ApiResponse::created(['reservation' => new ReservationResource($reservation->load('lead', 'project', 'property'))]);
    }

    public function act(Request $request, string $uuid, string $action)
    {
        $reservation = Reservation::where('uuid', $uuid)->with('lead')->firstOrFail();
        $this->authorize('update', $reservation->lead);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'deal_value' => ['nullable', 'numeric', 'min:0'],
        ]);

        $reservation = match ($action) {
            'confirm' => $this->reservations->confirm($reservation, $request->user()),
            'cancel' => $this->reservations->cancel($reservation, $request->user(), $data['reason'] ?? null),
            'convert' => $this->reservations->convert($reservation, $request->user(), isset($data['deal_value']) ? (int) round($data['deal_value'] * 100) : null),
            default => abort(404),
        };

        return ApiResponse::success(['reservation' => new ReservationResource($reservation->load('lead', 'project', 'property'))]);
    }
}
