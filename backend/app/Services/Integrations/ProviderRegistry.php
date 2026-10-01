<?php

namespace App\Services\Integrations;

use App\Enums\IntegrationProvider;
use App\Services\Integrations\Providers\CsvImportProvider;
use App\Services\Integrations\Providers\GenericWebhookProvider;
use App\Services\Integrations\Providers\GoogleAdsLeadProvider;
use App\Services\Integrations\Providers\GoogleSheetsProvider;
use App\Services\Integrations\Providers\LeadProviderInterface;
use App\Services\Integrations\Providers\LinkedInLeadProvider;
use App\Services\Integrations\Providers\ManualProvider;
use App\Services\Integrations\Providers\MetaLeadProvider;
use App\Services\Integrations\Providers\SnapchatLeadProvider;
use App\Services\Integrations\Providers\TikTokLeadProvider;
use InvalidArgumentException;

class ProviderRegistry
{
    /** @var array<string, class-string<LeadProviderInterface>> */
    private array $map = [
        IntegrationProvider::GenericWebhook->value => GenericWebhookProvider::class,
        IntegrationProvider::GoogleSheets->value => GoogleSheetsProvider::class,
        IntegrationProvider::Meta->value => MetaLeadProvider::class,
        IntegrationProvider::TikTok->value => TikTokLeadProvider::class,
        IntegrationProvider::GoogleAds->value => GoogleAdsLeadProvider::class,
        IntegrationProvider::LinkedIn->value => LinkedInLeadProvider::class,
        IntegrationProvider::Snapchat->value => SnapchatLeadProvider::class,
        IntegrationProvider::CsvImport->value => CsvImportProvider::class,
        IntegrationProvider::Manual->value => ManualProvider::class,
    ];

    public function get(IntegrationProvider|string $provider): LeadProviderInterface
    {
        $key = $provider instanceof IntegrationProvider ? $provider->value : $provider;

        if (! isset($this->map[$key])) {
            throw new InvalidArgumentException("Unknown lead provider [{$key}].");
        }

        return app($this->map[$key]);
    }

    /** Providers a tenant can configure from the Integrations page. */
    public function connectable(): array
    {
        return [
            IntegrationProvider::GenericWebhook,
            IntegrationProvider::GoogleSheets,
            IntegrationProvider::Meta,
            IntegrationProvider::TikTok,
            IntegrationProvider::GoogleAds,
            IntegrationProvider::LinkedIn,
            IntegrationProvider::Snapchat,
        ];
    }

    public function keys(): array
    {
        return array_keys($this->map);
    }
}
