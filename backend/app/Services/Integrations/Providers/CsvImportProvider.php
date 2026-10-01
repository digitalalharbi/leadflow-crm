<?php

namespace App\Services\Integrations\Providers;

use App\Enums\IntegrationProvider;

/** Rows coming from the CSV import wizard, already mapped by the user. */
class CsvImportProvider extends AbstractLeadProvider
{
    public function provider(): IntegrationProvider
    {
        return IntegrationProvider::CsvImport;
    }
}
