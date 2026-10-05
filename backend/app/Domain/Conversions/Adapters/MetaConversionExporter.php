<?php

namespace App\Domain\Conversions\Adapters;

use App\Domain\Conversions\Hashing;
use App\Models\ConversionExport;
use App\Models\Integration;

/**
 * Meta's Conversions API.
 *
 * For a lead that came from a Meta lead form, the strongest signal is the
 * `lead_id` Meta itself issued — it matches exactly, where a hashed phone
 * matches only when the buyer used the same number on Facebook. Both are sent
 * when both exist; Meta uses whichever it can resolve.
 */
class MetaConversionExporter extends AbstractConversionExporter
{
    public function platform(): string
    {
        return 'meta';
    }

    public function requiredCredentials(): array
    {
        return ['pixel_id', 'access_token'];
    }

    protected function endpoint(Integration $integration): string
    {
        $pixel = data_get($integration->credentials, 'pixel_id');

        return "https://graph.facebook.com/v21.0/{$pixel}/events";
    }

    protected function body(ConversionExport $export, Integration $integration): array
    {
        $lead = $export->lead;

        $userData = array_filter([
            'ph' => Hashing::phone($lead->phone_normalized ?? $lead->phone),
            'em' => Hashing::email($lead->email),
            'fn' => Hashing::name($lead->first_name),
            'ln' => Hashing::name($lead->last_name),
            // Not hashed: Meta issued it, and it identifies the click, not the person.
            'lead_id' => $lead->provider === 'meta' ? $lead->provider_lead_id : null,
            'fbc' => $lead->click_id,
        ]);

        return [
            'access_token' => data_get($integration->credentials, 'access_token'),
            'data' => [[
                'event_name' => $export->event->standardName(),
                'event_time' => $export->occurred_at->timestamp,
                'action_source' => 'system_generated',
                'event_id' => 'leadflow-'.$export->id,
                'user_data' => $userData,
                'custom_data' => array_filter([
                    'value' => $export->value,
                    'currency' => $export->currency,
                ]),
            ]],
        ];
    }
}
