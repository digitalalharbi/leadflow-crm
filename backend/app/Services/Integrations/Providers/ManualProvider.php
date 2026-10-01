<?php

namespace App\Services\Integrations\Providers;

use App\Enums\IntegrationProvider;

/** A lead typed in by a sales rep. */
class ManualProvider extends AbstractLeadProvider
{
    public function provider(): IntegrationProvider
    {
        return IntegrationProvider::Manual;
    }
}
