<?php

namespace App\Services\Integrations\Providers;

use App\Enums\IntegrationProvider;

/**
 * The workhorse of the MVP: any landing page, website form or no-code tool can
 * POST JSON to a per-tenant endpoint and the tenant maps the fields in the UI.
 */
class GenericWebhookProvider extends AbstractLeadProvider
{
    public function provider(): IntegrationProvider
    {
        return IntegrationProvider::GenericWebhook;
    }
}
