<?php

namespace App\Domain\Tracking\Destinations\Adapters;

use App\Models\TrackingDestination;

/** TikTok's Events API for the web event: the form submit, under the id the page's pixel used. The stages go through the offline pipeline. */
class TikTokAdapter extends AbstractAdapter
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
        $eventName = ((array) $destination->event_map)[$payload['event']] ?? 'SubmitForm';

        $ids = (array) ($payload['click_ids'] ?? []);
        $identifiers = (array) ($payload['identifiers'] ?? []);
        $pixel = $this->config($destination, 'pixel_id');

        $body = [
            'event_source' => 'web',
            'event_source_id' => $pixel,
            'data' => [array_filter([
                'event' => $eventName,
                'event_time' => strtotime($payload['occurred_at']),
                'event_id' => $payload['event_id'],
                'user' => array_filter(['email' => $identifiers['em'] ?? null, 'phone' => $identifiers['ph'] ?? null, 'ttclid' => $ids['ttclid'] ?? null]) ?: null,
                'page' => isset($payload['url']) ? ['url' => $payload['url']] : null,
                'properties' => array_filter(['project_id' => $payload['project_id'] ?? null, 'tracking_link_id' => $payload['tracking_link_id'] ?? null]) ?: null,
            ])],
        ];

        return $this->post('https://business-api.tiktok.com/open_api/v1.3/event/track/', $body, ['Access-Token' => $this->credential($destination, 'access_token')], ['event' => $eventName, 'event_id' => $payload['event_id']]);
    }
}
