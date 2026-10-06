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

    /**
     * Meta's own field names. The {name, values[]} envelope is flattened by the
     * base provider, since those payloads also arrive at generic endpoints.
     */
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

    public function pullsLeads(): bool
    {
        return true;
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
