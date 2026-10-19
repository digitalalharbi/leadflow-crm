<?php

namespace App\Jobs;

use App\Domain\Conversions\ConversionCredentials;
use App\Domain\Conversions\ExporterRegistry;
use App\Models\ConversionExport;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Uploads one conversion.
 *
 * The important behaviour is what it does when it cannot. An unconfigured
 * integration is left in `awaiting_credentials` and not retried: a workspace
 * that has not connected Meta has not done anything wrong, and burning three
 * retries plus a failed-job alert against every won lead turns a setup step
 * into an alert storm — the kind that teaches a team to ignore alerts.
 *
 * A genuine API failure does retry, because that is a condition that passes.
 */
class SendConversionExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** Minutes, widening: an ad platform having a bad hour is common. */
    public array $backoff = [60, 300, 1800];

    public function __construct(public readonly int $exportId) {}

    public function handle(ExporterRegistry $registry, TenantContext $tenants): void
    {
        $export = ConversionExport::withoutGlobalScopes()->find($this->exportId);

        if (! $export || $export->status === ConversionExport::SENT) {
            return;
        }

        $tenants->withTenant($export->tenant, function () use ($export, $registry) {
            $exporter = $registry->get($export->platform);

            $credentials = ConversionCredentials::for($export->platform);

            if (! $exporter->isConfigured($credentials)) {
                // Waiting, not failing. The sweep picks these up again once the
                // workspace connects the account, including ones recorded
                // months earlier.
                $export->update([
                    'status' => ConversionExport::AWAITING_CREDENTIALS,
                    'last_error' => null,
                ]);

                return;
            }

            $result = $exporter->send($export, $credentials);

            $export->update([
                'attempts' => $export->attempts + 1,
                'request_summary' => $result['summary'],
                'status' => $result['ok'] ? ConversionExport::SENT : ConversionExport::FAILED,
                'last_error' => $result['ok'] ? null : ($result['error'] ?? 'Unknown error'),
                'sent_at' => $result['ok'] ? now() : null,
            ]);

            // Retried by the queue, not by a loop here, so the backoff applies.
            if (! $result['ok']) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 1800);
            }
        });
    }
}
