<?php

namespace App\Domain\Conversions\Adapters;

use App\Domain\Conversions\Hashing;
use App\Models\ConversionExport;

/**
 * TikTok Events API 2.0, offline event set. Authenticated with the
 * `Access-Token` header; TikTok answers 200 with a non-zero `code` when it
 * refuses, so the body is read, not just the status line.
 */
class TikTokConversionExporter extends AbstractConversionExporter
{
    public function platform(): string
    {
        return 'tiktok';
    }

    public function requiredCredentials(): array
    {
        return ['access_token', 'event_set_id'];
    }

    protected function endpoint(array $credentials): string
    {
        return 'https://business-api.tiktok.com/open_api/v1.3/event/track/';
    }

    protected function headers(array $credentials, string $url, array $body): array
    {
        return ['Access-Token' => (string) ($credentials['access_token'] ?? '')];
    }

    protected function accepted(array $json): bool
    {
        return (int) ($json['code'] ?? -1) === 0;
    }

    protected function body(ConversionExport $export, array $credentials): array
    {
        $lead = $export->lead;

        return [
            'event_source' => 'offline',
            'event_source_id' => $credentials['event_set_id'] ?? null,
            'data' => [[
                'event' => $export->event->standardName(),
                'event_time' => $export->occurred_at->timestamp,
                'event_id' => 'leadflow-'.$export->id,
                'user' => array_filter([
                    'phone' => Hashing::phone($lead->phone_normalized ?? $lead->phone),
                    'email' => Hashing::email($lead->email),
                    'ttclid' => $lead->click_id,
                ]),
                'properties' => array_filter([
                    'value' => $export->value,
                    'currency' => $export->currency,
                ]),
            ]],
        ];
    }
}
