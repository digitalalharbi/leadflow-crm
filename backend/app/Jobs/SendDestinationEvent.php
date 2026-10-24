<?php

namespace App\Jobs;

use App\Domain\Tracking\Destinations\DestinationFanOut;
use App\Domain\Tracking\Destinations\DestinationPayload;
use App\Models\DestinationDelivery;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TrackingDestination;
use App\Models\TrackingEvent;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends one event to one destination.
 *
 * The lead and the event are already saved; this is a courtesy call that may fail, retry and fail again without
 * costing anybody a lead. A destination having a bad hour is common, so it backs off — a minute, five, thirty.
 * Each attempt is written on the delivery, and the destination shows that it is failing.
 */
class SendDestinationEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    /** @var list<int> seconds */
    public array $backoff = [60, 300, 1800];

    public function __construct(public readonly int $deliveryId) {}

    public function handle(DestinationFanOut $fanOut, DestinationPayload $payloads, TenantContext $tenants): void
    {
        $delivery = DestinationDelivery::withoutGlobalScopes()->find($this->deliveryId);

        if ($delivery === null || in_array($delivery->status, [DestinationDelivery::SENT, DestinationDelivery::SKIPPED, DestinationDelivery::AWAITING_CREDENTIALS], true)) {
            return;
        }

        $tenants->withTenant($delivery->tenant()->withoutGlobalScopes()->first() ?? Tenant::withoutGlobalScopes()->findOrFail($delivery->tenant_id), function () use ($delivery, $fanOut, $payloads) {
            $destination = TrackingDestination::find($delivery->destination_id);
            $event = TrackingEvent::find($delivery->tracking_event_id);
            $adapter = $destination ? $fanOut->adapter($destination) : null;

            if ($destination === null || $event === null || $adapter === null) {
                $delivery->forceFill(['status' => DestinationDelivery::SKIPPED, 'reason' => 'gone'])->save();

                return;
            }

            $lead = $event->lead_id ? Lead::find($event->lead_id) : null;
            $consent = (string) ($event->consent ?? $lead?->tracking_consent ?? 'unknown');

            $result = $adapter->send($destination, $payloads->build($event, $lead, $destination, $consent));

            $delivery->forceFill([
                'attempts' => $delivery->attempts + 1,
                'status' => $result['ok'] ? DestinationDelivery::SENT : DestinationDelivery::FAILED,
                'last_error' => $result['ok'] ? null : mb_substr((string) ($result['error'] ?? 'Unknown error'), 0, 500),
                'request_summary' => $result['summary'] ?: null,
                'sent_at' => $result['ok'] ? now() : null,
            ])->save();

            $destination->forceFill($result['ok']
                ? ['last_event_at' => now(), 'last_error' => null]
                : ['last_error' => $delivery->last_error])->saveQuietly();

            // Failing is how the queue is told to try again.
            if (! $result['ok']) {
                throw new \RuntimeException($delivery->last_error);
            }
        });
    }
}
