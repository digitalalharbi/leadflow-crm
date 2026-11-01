<?php

namespace App\Domain\Tracking\Destinations\Adapters;

use App\Models\TrackingDestination;

/**
 * Google Analytics 4's Measurement Protocol, for what happens after the form: the page's own gtag reports the
 * visit and the form, so the server only adds the stages (qualified, interested, booked, reserved, won, revenue) —
 * under the visitor's own client id, with no personal data at all.
 */
class Ga4Adapter extends AbstractAdapter
{
    private const NAMES = [
        'qualified' => 'qualify_lead', 'interested' => 'working_lead', 'appointment_requested' => 'appointment_requested', 'appointment_booked' => 'appointment_booked',
        'visit_completed' => 'visit_completed', 'reservation_created' => 'reservation_created', 'won' => 'close_convert_lead', 'lost' => 'close_unconvert_lead', 'revenue_recorded' => 'purchase',
    ];

    public function declines(string $canonicalEvent): ?string
    {
        return isset(self::NAMES[$canonicalEvent]) ? null : 'browser_covers';
    }

    public function send(TrackingDestination $destination, array $payload): array
    {
        $eventName = ((array) $destination->event_map)[$payload['event']] ?? self::NAMES[$payload['event']];

        $params = array_filter([
            'event_id' => $payload['event_id'], 'project_id' => $payload['project_id'] ?? null, 'tracking_link_id' => $payload['tracking_link_id'] ?? null,
            'campaign_id' => $payload['campaign_id'] ?? null, 'platform' => $payload['platform'] ?? null, 'lead_stage' => $payload['lead_stage'] ?? null,
            'value' => $payload['value'] ?? null, 'currency' => $payload['currency'] ?? null,
            // GA4 counts one purchase per transaction id: a replay is the same sale.
            'transaction_id' => $payload['event'] === 'revenue_recorded' ? $payload['event_id'] : null,
        ], fn ($v) => $v !== null);

        $url = 'https://www.google-analytics.com/mp/collect?'.http_build_query([
            'measurement_id' => $this->config($destination, 'measurement_id'), 'api_secret' => $this->credential($destination, 'api_secret'),
        ]);

        return $this->post($url, [
            'client_id' => $payload['visitor_id'],
            'events' => [['name' => $eventName, 'params' => $params]],
        ], [], ['event' => $eventName, 'event_id' => $payload['event_id']]);
    }
}
