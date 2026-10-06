<?php

namespace App\Services\Integrations\Providers;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pulls rows from a Google Sheet. PostgreSQL stays the source of truth — the
 * sheet is only an inbound source, never read back as state.
 *
 * Without OAuth credentials the provider runs in sandbox mode and returns an
 * empty result set rather than failing, so the rest of the product is testable.
 */
class GoogleSheetsProvider extends AbstractLeadProvider
{
    public function provider(): IntegrationProvider
    {
        return IntegrationProvider::GoogleSheets;
    }

    public function requiredCredentials(): array
    {
        return ['access_token'];
    }

    public function pullsLeads(): bool
    {
        return true;
    }

    public function fetch(Integration $integration): array
    {
        if (! $this->isConfigured($integration)) {
            Log::info('google_sheets.sandbox', ['integration' => $integration->uuid]);

            return [];
        }

        $config = $integration->config ?? [];
        $spreadsheetId = $config['spreadsheet_id'] ?? null;
        $range = $config['range'] ?? 'Sheet1!A:Z';
        $lastRow = (int) ($config['last_processed_row'] ?? 1);

        if (! $spreadsheetId) {
            return [];
        }

        $response = Http::withToken($integration->credentials['access_token'])
            ->timeout(30)
            ->get("https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$range}");

        if ($response->failed()) {
            throw new \RuntimeException('Google Sheets API error: '.$response->status());
        }

        $values = $response->json('values', []);

        if (count($values) < 2) {
            return [];
        }

        $headers = array_map(fn ($h) => trim((string) $h), array_shift($values));
        $rows = [];

        foreach ($values as $index => $row) {
            $rowNumber = $index + 2; // 1-based + header row

            if ($rowNumber <= $lastRow) {
                continue; // already processed; prevents duplicate imports
            }

            $assoc = [];
            foreach ($headers as $i => $header) {
                $assoc[$header] = $row[$i] ?? null;
            }
            $assoc['provider_lead_id'] = $spreadsheetId.':'.$rowNumber;
            $rows[] = $assoc;
        }

        if ($rows !== []) {
            $integration->update([
                'config' => array_merge($config, ['last_processed_row' => count($values) + 1]),
            ]);
        }

        return $rows;
    }
}
