<?php

namespace App\Domain\Tracking\Destinations;

use App\Domain\Tracking\Destinations\Adapters\Ga4Adapter;
use App\Domain\Tracking\Destinations\Adapters\MetaAdapter;
use App\Domain\Tracking\Destinations\Adapters\SignedPostAdapter;
use App\Domain\Tracking\Destinations\Adapters\TikTokAdapter;
use App\Domain\Tracking\EventType;
use App\Jobs\SendDestinationEvent;
use App\Models\DestinationDelivery;
use App\Models\Lead;
use App\Models\TrackingDestination;
use App\Models\TrackingEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * An event, told to each destination that asked for it — once.
 *
 * Every decision that can be made without a network call is made here and written down, so a delivery that did
 * not happen says why (not asked for, no consent, the page already reports it, waiting for a credential). What
 * is left is queued: the call itself never runs inside the request that caused the event, and never fails it.
 */
class DestinationFanOut
{
    /** The events a destination may be told about that happen on the server. */
    private function eventName(TrackingEvent $event): ?string
    {
        $canonical = EventType::from($event->event_type)->canonical();

        return in_array($canonical, DestinationKind::EVENTS, true) ? $canonical : null;
    }

    /** Never throws: an event is saved whether or not anybody can be told about it. */
    public function queue(TrackingEvent $event): void
    {
        try {
            $name = $this->eventName($event);

            if ($name === null || blank($event->event_id)) {
                return;
            }

            $destinations = TrackingDestination::query()->where('is_enabled', true)
                ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $event->project_id))->get()
                ->filter(fn (TrackingDestination $d) => $d->destinationKind()->server());

            if ($destinations->isEmpty()) {
                return;
            }

            $lead = $event->lead_id ? Lead::query()->find($event->lead_id) : null;
            $consent = (string) ($event->consent ?? $lead?->tracking_consent ?? 'unknown');

            foreach ($destinations as $destination) {
                $this->deliver($destination, $event, $name, $consent);
            }
        } catch (\Throwable $e) {
            Log::warning('destination_fanout_failed', ['event' => $event->id, 'error' => $e->getMessage()]);
        }
    }

    private function deliver(TrackingDestination $destination, TrackingEvent $event, string $name, string $consent): void
    {
        $delivery = $this->claim($destination, $event, $name);

        // Already decided once (a replay, a second pass): the first decision stands.
        if ($delivery === null || $delivery->status !== DestinationDelivery::PENDING || $delivery->attempts > 0) {
            return;
        }

        $skip = $this->skipReason($destination, $name, $consent);

        if ($skip !== null) {
            $delivery->forceFill(['status' => $skip === 'awaiting_credentials' ? DestinationDelivery::AWAITING_CREDENTIALS : DestinationDelivery::SKIPPED, 'reason' => $skip === 'awaiting_credentials' ? null : $skip])->save();

            return;
        }

        SendDestinationEvent::dispatch($delivery->id)->afterCommit();
    }

    /** One row per event per destination: the unique key makes a replay find the first. */
    private function claim(TrackingDestination $destination, TrackingEvent $event, string $name): ?DestinationDelivery
    {
        try {
            return DB::transaction(fn () => DestinationDelivery::create([
                'destination_id' => $destination->id, 'tracking_event_id' => $event->id, 'event_id' => $event->event_id, 'event_name' => $name, 'status' => DestinationDelivery::PENDING,
            ]));
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                return null;
            }

            throw $e;
        }
    }

    private function skipReason(TrackingDestination $destination, string $name, string $consent): ?string
    {
        if (! in_array($name, $destination->allowedEvents(), true)) {
            return 'not_allowed';
        }

        if (($declined = $this->adapter($destination)?->declines($name)) !== null) {
            return $declined;
        }

        $consentReason = match (true) {
            $consent === 'denied' => 'consent_denied',
            $consent !== 'granted' && $destination->consent_mode !== 'unless_denied' => 'consent_missing',
            default => null,
        };

        if ($consentReason !== null) {
            return $consentReason;
        }

        return match ($destination->readiness()) {
            'ready' => null,
            'not_configured' => 'not_configured',
            default => 'awaiting_credentials',
        };
    }

    public function adapter(TrackingDestination $destination): ?DestinationAdapter
    {
        return match ($destination->destinationKind()) {
            DestinationKind::Ga4 => new Ga4Adapter,
            DestinationKind::Meta => new MetaAdapter,
            DestinationKind::TikTok => new TikTokAdapter,
            DestinationKind::GtmServer, DestinationKind::Webhook => new SignedPostAdapter,
            default => null,
        };
    }
}
