<?php

namespace App\Domain\Visits;

use App\Domain\Conversions\ConversionEvent;
use App\Domain\Conversions\ConversionRecorder;
use App\Domain\Leads\OutcomeRecorder;
use App\Domain\Tracking\EventRecorder;
use App\Domain\Tracking\EventType;
use App\Enums\ActivityType;
use App\Events\LeadSignal;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Leads\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Holding a unit for a lead: pending → confirmed → converted (the sale), or cancelled / expired.
 *
 * The unit is held from the moment the reservation is made, so two reps cannot promise the same
 * apartment; it is released when the reservation ends any way but a sale, and becomes sold (or
 * rented) when it converts. A reservation nobody confirms lapses on its own — the hold is a
 * promise with a clock, not a way to take a unit off the market forever.
 */
class ReservationService
{
    public const HOLD_DAYS = 3;

    private const ENDED = ['cancelled', 'expired', 'converted'];

    public function __construct(private ActivityLogger $activities, private OutcomeRecorder $outcomes) {}

    /**
     * One open reservation per lead: asking twice is still holding once.
     *
     * @param  array{amount_minor?:?int, expires_at?:mixed, notes?:?string}  $data
     */
    public function create(Lead $lead, ?User $actor, ?Property $property = null, array $data = []): Reservation
    {
        $open = Reservation::where('lead_id', $lead->id)->whereIn('status', Reservation::OPEN)->latest('id')->first();

        if ($open) {
            return $open;
        }

        $property ??= $lead->interested_property_id ? Property::find($lead->interested_property_id) : null;

        if ($property && $property->status !== 'available') {
            // A unit that is not on the market cannot be held for somebody else.
            if ($property->status !== 'reserved' || ! $this->heldFor($property, $lead)) {
                throw ValidationException::withMessages(['property_id' => ['This unit is not available.']]);
            }
        }

        return DB::transaction(function () use ($lead, $actor, $property, $data) {
            $reservation = Reservation::create([
                'lead_id' => $lead->id,
                'project_id' => $property?->project_id ?? $lead->project_id,
                'property_id' => $property?->id,
                'created_by' => $actor?->id,
                'status' => 'pending',
                'amount_minor' => $data['amount_minor'] ?? null,
                'reserved_at' => now(),
                'expires_at' => $data['expires_at'] ?? now()->addDays(self::HOLD_DAYS),
                'notes' => $data['notes'] ?? null,
            ]);

            $property?->forceFill(['status' => 'reserved'])->save();

            $this->activities->log($lead, ActivityType::ReservationCreated, $actor, null, ['reservation_id' => $reservation->uuid, 'property_id' => $property?->uuid]);
            app(EventRecorder::class)->outcome($lead, EventType::Reservation, ['reservation' => $reservation->uuid], $reservation->uuid);
            // The signal a campaign can be optimised on, long before the sale.
            app(ConversionRecorder::class)->record($lead, ConversionEvent::Reservation);
            // A trigger for automations and outbound webhooks, after the hold is committed.
            DB::afterCommit(fn () => LeadSignal::dispatch($lead, 'lead.reservation_created', ['reservation' => $reservation->uuid], $actor));

            return $reservation;
        });
    }

    public function confirm(Reservation $reservation, ?User $actor): Reservation
    {
        $this->mustBe($reservation, ['pending']);

        // The deposit has been agreed: the hold no longer lapses on its own.
        $reservation->forceFill(['status' => 'confirmed', 'reserved_at' => now(), 'expires_at' => null])->save();
        $this->activities->log($reservation->lead, ActivityType::ReservationUpdated, $actor, null, ['reservation_id' => $reservation->uuid, 'to' => 'confirmed']);

        return $reservation->refresh();
    }

    public function cancel(Reservation $reservation, ?User $actor, ?string $reason = null): Reservation
    {
        $this->mustBe($reservation, Reservation::OPEN);
        $this->end($reservation, 'cancelled', $actor, ['cancelled_at' => now(), 'cancel_reason' => $reason]);

        return $reservation->refresh();
    }

    /** The sale: the reservation converts, the unit is sold, the lead is won at the unit's price unless told otherwise. */
    public function convert(Reservation $reservation, User $actor, ?int $dealValueMinor = null): Reservation
    {
        $this->mustBe($reservation, Reservation::OPEN);

        DB::transaction(function () use ($reservation, $actor, $dealValueMinor) {
            $lead = $reservation->lead;
            $property = $reservation->property;
            $value = $dealValueMinor ?? $property?->price_minor ?? $reservation->amount_minor;

            $this->end($reservation, 'converted', $actor, ['converted_at' => now()]);

            if ($property) {
                $property->forceFill(['status' => $property->transaction_type === 'rent' ? 'rented' : 'sold'])->save();
            }

            // Set, not saved: it goes in the same save that makes the lead won, so the sale event
            // carries the figure and a not-yet-won lead is not asked to hold one.
            if ($value) {
                $lead->deal_value_minor = $value;
            }

            $this->outcomes->record($lead, 'won', $actor, null, 'reservation');
        });

        return $reservation->refresh();
    }

    /** Pending holds past their time lapse and release the unit. Returns how many. */
    public function expireDue(): int
    {
        $count = 0;

        Reservation::query()->where('status', 'pending')->whereNotNull('expires_at')->where('expires_at', '<', now())
            ->with(['lead', 'property'])->limit(500)->get()
            ->each(function (Reservation $reservation) use (&$count) {
                $this->end($reservation, 'expired', null, []);
                $count++;
            });

        return $count;
    }

    private function end(Reservation $reservation, string $to, ?User $actor, array $stamp): void
    {
        $reservation->forceFill(['status' => $to] + $stamp)->save();

        $property = $reservation->property;

        // A unit that was held for this reservation goes back on the market — unless it is sold.
        if ($property && $to !== 'converted' && $property->status === 'reserved' && ! Reservation::where('property_id', $property->id)->whereIn('status', Reservation::OPEN)->where('id', '!=', $reservation->id)->exists()) {
            $property->forceFill(['status' => 'available'])->save();
        }

        $this->activities->log($reservation->lead, ActivityType::ReservationUpdated, $actor, $stamp['cancel_reason'] ?? null, ['reservation_id' => $reservation->uuid, 'to' => $to]);
    }

    /** A unit already held for this very lead is theirs to reserve. */
    private function heldFor(Property $property, Lead $lead): bool
    {
        return Reservation::where('property_id', $property->id)->whereIn('status', Reservation::OPEN)->where('lead_id', $lead->id)->exists();
    }

    private function mustBe(Reservation $reservation, array $statuses): void
    {
        if (! in_array($reservation->status, $statuses, true)) {
            throw ValidationException::withMessages(['status' => ["A {$reservation->status} reservation cannot do that."]]);
        }
    }
}
