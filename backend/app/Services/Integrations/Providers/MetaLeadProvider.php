<?php

namespace App\Services\Integrations\Providers;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * Facebook / Instagram Lead Ads. Meta delivers leads either via the Leadgen
 * webhook or by polling /{form_id}/leads; both arrive as a field_data array.
 */
class MetaLeadProvider extends AbstractLeadProvider
{
    public function provider(): IntegrationProvider
    {
        return IntegrationProvider::Meta;
    }

    public function requiredCredentials(): array
    {
        return ['access_token'];
    }

    /** Meta sends answers as [{name, values[]}] rather than a flat object. */
    public function toIncomingLead(array $payload, ?Integration $integration = null): \App\DataTransferObjects\IncomingLead
    {
        if (isset($payload['field_data']) && is_array($payload['field_data'])) {
            $flat = [];

            foreach ($payload['field_data'] as $field) {
                $name = Arr::get($field, 'name');
                $value = Arr::get($field, 'values.0');

                if ($name !== null) {
                    $flat[$name] = $value;
                }
            }

            $flat += [
                'provider_lead_id' => Arr::get($payload, 'id'),
                'form_id' => Arr::get($payload, 'form_id'),
                'form_name' => Arr::get($payload, 'form_name'),
                'campaign_id' => Arr::get($payload, 'campaign_id'),
                'campaign_name' => Arr::get($payload, 'campaign_name'),
                'adset_id' => Arr::get($payload, 'adset_id'),
                'adset_name' => Arr::get($payload, 'adset_name'),
                'ad_id' => Arr::get($payload, 'ad_id'),
                'ad_name' => Arr::get($payload, 'ad_name'),
                'platform' => Arr::get($payload, 'platform', 'meta'),
            ];

            $payload = array_filter($flat, fn ($v) => $v !== null) + ['_raw' => true];
        }

        return parent::toIncomingLead($payload, $integration);
    }

    protected function aliases(): array
    {
        return parent::aliases() + [
            'full_name' => 'full_name',
            'phone_number' => 'phone',
            'email' => 'email',
            'city' => 'city',
            'street_address' => 'preferred_location',
        ];
    }

    public function fetch(Integration $integration): array
    {
        if (! $this->isConfigured($integration)) {
            return [];
        }

        $formId = Arr::get($integration->config, 'form_id');

        if (! $formId) {
            return [];
        }

        $response = Http::withToken($integration->credentials['access_token'])
            ->timeout(30)
            ->get("https://graph.facebook.com/v21.0/{$formId}/leads", [
                'limit' => 100,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Meta Graph API error: '.$response->status());
        }

        return $response->json('data', []);
    }
}
