<?php

namespace App\Jobs;

use App\Domain\Notifications\ImportantAlerts;
use App\Domain\Sheets\GoogleTokens;
use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Tenant;
use App\Support\CsvWriter;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * An optional copy of a new lead on a spreadsheet the company chose. Mohtam stays the source of truth: this runs after the lead
 * exists, in the queue, and a Google that is down, revoked or refusing costs a row on a sheet, never a lead.
 */
class MirrorLeadToSheet implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $leadId, public int $tenantId, public string $spreadsheetId, public ?string $tab = null) {}

    public function handle(TenantContext $context, GoogleTokens $tokens): void
    {
        $tenant = $context->withoutScope(fn () => Tenant::find($this->tenantId));

        if ($tenant === null) {
            return;
        }

        $context->withTenant($tenant, function () use ($tokens) {
            $lead = Lead::with('project:id,name')->find($this->leadId);
            $connection = Integration::where('provider', IntegrationProvider::GoogleSheets->value)->where('is_active', true)->latest('id')->first();
            $token = $connection ? $tokens->accessToken($connection) : null;

            if ($lead === null || blank($token)) {
                Log::warning('lead_mirror.skipped', ['lead' => $this->leadId, 'reason' => $lead === null ? 'no_lead' : 'no_google_connection']);

                return;
            }

            // Raw, and neutralised: a name typed as a formula stays text on the company's sheet.
            $row = array_map([CsvWriter::class, 'neutralise'], [
                $lead->created_at?->toDateTimeString(), $lead->full_name, $lead->phone_normalized ?? $lead->phone, $lead->email, $lead->city,
                $lead->project?->name, $lead->platform, $lead->campaign_name, $lead->utm_source,
            ]);
            $range = rawurlencode(($this->tab ? "'".str_replace("'", "''", $this->tab)."'!" : '').'A1');

            $response = Http::withToken($token)->timeout(20)->post(
                "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}/values/{$range}:append?valueInputOption=RAW&insertDataOption=INSERT_ROWS",
                ['values' => [$row]],
            );

            if ($response->failed()) {
                // The status, never the row: it is a customer's name and number.
                Log::warning('lead_mirror.failed', ['lead' => $this->leadId, 'status' => $response->status()]);

                if ($response->serverError() && $this->attempts() < 4) {
                    // 2, 4, then 8 minutes. The lead is safe either way: this is only its copy.
                    $this->release(60 * 2 ** $this->attempts());

                    return;
                }

                // The last attempt, or an answer that will not change.
                ImportantAlerts::integrationFailure($this->tenantId, 'sheet_mirror_failed', 'mirror:'.$this->spreadsheetId);
            }
        });
    }
}
