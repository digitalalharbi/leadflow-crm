<?php

namespace App\Services\Integrations\Providers;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/** Snapchat Lead Generation forms. */
class SnapchatLeadProvider extends AbstractLeadProvider
{
    public function provider(): IntegrationProvider
    {
        return IntegrationProvider::Snapchat;
    }

    public function requiredCredentials(): array
    {
        return ['access_token', 'ad_account_id'];
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

        $accountId = $integration->credentials['ad_account_id'];

        $response = Http::withToken($integration->credentials['access_token'])
            ->timeout(30)
            ->get("https://adsapi.snapchat.com/v1/adaccounts/{$accountId}/leads");

        if ($response->failed()) {
            throw new \RuntimeException('Snapchat Marketing API error: '.$response->status());
        }

        return Arr::get($response->json(), 'leads', []);
    }
}
