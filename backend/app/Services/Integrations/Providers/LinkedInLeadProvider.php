<?php

namespace App\Services\Integrations\Providers;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use Illuminate\Support\Facades\Http;

/** LinkedIn Lead Gen Forms. */
class LinkedInLeadProvider extends AbstractLeadProvider
{
    public function provider(): IntegrationProvider
    {
        return IntegrationProvider::LinkedIn;
    }

    public function requiredCredentials(): array
    {
        return ['access_token', 'account_id'];
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

        $response = Http::withToken($integration->credentials['access_token'])
            ->timeout(30)
            ->get('https://api.linkedin.com/rest/leadFormResponses', [
                'q' => 'owner',
                'owner' => '(sponsoredAccount:urn:li:sponsoredAccount:'.$integration->credentials['account_id'].')',
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('LinkedIn API error: '.$response->status());
        }

        return $response->json('elements', []);
    }
}
