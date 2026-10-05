<?php

namespace App\Domain\Conversions\Adapters;

use App\Domain\Conversions\Hashing;
use App\Models\ConversionExport;
use App\Models\Integration;

/** TikTok's Events API, offline variant. */
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

    protected function endpoint(Integration $integration): string
    {
        return 'https://business-api.tiktok.com/open_api/v1.3/event/track/';
    }

    protected function body(ConversionExport $export, Integration $integration): array
    {
        $lead = $export->lead;

        return [
            'event_source' => 'offline',
            'event_source_id' => data_get($integration->credentials, 'event_set_id'),
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
