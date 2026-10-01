<?php

namespace App\Services\Integrations\Providers;

use App\Enums\IntegrationProvider;

/**
 * Google Ads Lead Form extensions. Google pushes to a configured webhook URL
 * with a shared `google_key`; we accept it through the generic webhook route
 * with this provider selected, so no polling is required.
 */
class GoogleAdsLeadProvider extends AbstractLeadProvider
{
    public function provider(): IntegrationProvider
    {
        return IntegrationProvider::GoogleAds;
    }

    public function requiredCredentials(): array
    {
        return ['google_key'];
    }

    protected function aliases(): array
    {
        return parent::aliases() + [
            'user_column_data' => 'full_name',
            'lead_id' => 'provider_lead_id',
            'campaign_id' => 'campaign_id',
            'gcl_id' => 'source',
        ];
    }
}
