<?php

namespace App\Domain\Tracking\Destinations\Adapters;

use App\Models\TrackingDestination;

/**
 * Meta's Conversions API for the web event: the form submit, under the very id the page's pixel used, so Meta counts one lead.
 * The later stages go through the offline-conversion pipeline, once.
 */
class MetaAdapter extends AbstractAdapter
{
    public function declines(string $canonicalEvent): ?string
    {
        return match (true) {
            $canonicalEvent === 'form_submitted' => null,
            $canonicalEvent === 'lead_created' => 'browser_covers',
            default => 'via_conversions',
        };
    }

    public function send(TrackingDestination $destination, array $payload): array
    {
        $ids = (array) ($payload['click_ids'] ?? []);
        $identifiers = (array) ($payload['identifiers'] ?? []);

        $userData = array_filter([
            'em' => isset($identifiers['em']) ? [$identifiers['em']] : null,
            'ph' => isset($identifiers['ph']) ? [$identifiers['ph']] : null,
            // Built from the click id Meta issued: it is what lets the sale be credited to the ad.
            'fbc' => isset($ids['fbclid']) ? 'fb.1.'.(strtotime($payload['occurred_at']) * 1000).'.'.$ids['fbclid'] : null,
        ]);

        $eventName = ((array) $destination->event_map)[$payload['event']] ?? 'Lead';
        $pixel = $this->config($destination, 'pixel_id');
        $body = [
            'data' => [array_filter([
                'event_name' => $eventName,
                'event_time' => strtotime($payload['occurred_at']),
                'event_id' => $payload['event_id'],
                'action_source' => 'website',
                'event_source_url' => $payload['url'] ?? null,
                'user_data' => $userData ?: null,
                'custom_data' => array_filter(['project_id' => $payload['project_id'] ?? null, 'tracking_link_id' => $payload['tracking_link_id'] ?? null]) ?: null,
            ])],
        ] + (filled($this->config($destination, 'test_event_code')) ? ['test_event_code' => $this->config($destination, 'test_event_code')] : []);

        return $this->post(
            "https://graph.facebook.com/v21.0/{$pixel}/events?access_token=".urlencode($this->credential($destination, 'access_token')),
            $body, [], ['event' => $eventName, 'event_id' => $payload['event_id']],
        );
    }
}
