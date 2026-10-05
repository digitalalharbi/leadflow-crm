<?php

namespace App\Domain\Conversions\Adapters;

use App\Domain\Conversions\Hashing;
use App\Models\ConversionExport;
use App\Models\Integration;

/** Snapchat's Conversions API. */
class SnapchatConversionExporter extends AbstractConversionExporter
{
    public function platform(): string
    {
        return 'snapchat';
    }

    public function requiredCredentials(): array
    {
        return ['access_token', 'pixel_id'];
    }

    protected function endpoint(Integration $integration): string
    {
        return 'https://tr.snapchat.com/v3/'
            .data_get($integration->credentials, 'pixel_id')
            .'/events';
    }

    protected function body(ConversionExport $export, Integration $integration): array
    {
        $lead = $export->lead;

        return [
            'event_name' => $export->event->standardName(),
            'event_time' => $export->occurred_at->timestamp,
            'event_source_url' => null,
            'action_source' => 'OFFLINE',
            'event_id' => 'leadflow-'.$export->id,
            'user_data' => array_filter([
                'ph' => Hashing::phone($lead->phone_normalized ?? $lead->phone),
                'em' => Hashing::email($lead->email),
                'sc_click_id' => $lead->click_id,
            ]),
            'custom_data' => array_filter([
                'value' => $export->value,
                'currency' => $export->currency,
            ]),
        ];
    }
}
