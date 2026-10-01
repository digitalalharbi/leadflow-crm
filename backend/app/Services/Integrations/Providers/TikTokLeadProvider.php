<?php

namespace App\Services\Integrations\Providers;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/** TikTok Lead Generation instant forms. */
class TikTokLeadProvider extends AbstractLeadProvider
{
    public function provider(): IntegrationProvider
    {
        return IntegrationProvider::TikTok;
    }

    public function requiredCredentials(): array
    {
        return ['access_token', 'advertiser_id'];
    }

    public function fetch(Integration $integration): array
    {
        if (! $this->isConfigured($integration)) {
            return [];
        }

        $response = Http::withHeaders([
            'Access-Token' => $integration->credentials['access_token'],
        ])->timeout(30)->get('https://business-api.tiktok.com/open_api/v1.3/pages/lead/task/', [
            'advertiser_id' => $integration->credentials['advertiser_id'],
            'page_id' => Arr::get($integration->config, 'page_id'),
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('TikTok Business API error: '.$response->status());
        }

        return $response->json('data.list', []);
    }
}
