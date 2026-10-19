<?php

namespace App\Domain\Conversions\Adapters;

use App\Domain\Conversions\Hashing;
use App\Models\ConversionExport;

/**
 * Snapchat Conversions API v3: events in a `data` array, authenticated with
 * the access token on the URL as Snap documents it.
 */
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

    protected function endpoint(array $credentials): string
    {
        return 'https://tr.snapchat.com/v3/'.rawurlencode((string) ($credentials['pixel_id'] ?? '')).'/events?access_token='.rawurlencode((string) ($credentials['access_token'] ?? ''));
    }

    protected function body(ConversionExport $export, array $credentials): array
    {
        $lead = $export->lead;

        return ['data' => [[
            'event_name' => $export->event->standardName(),
            'event_time' => $export->occurred_at->timestamp,
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
        ]]];
    }
}
