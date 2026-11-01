<?php

namespace App\Jobs;

use App\Domain\Exports\ExportBuilder;
use App\Models\DataExport;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Builds one export in the background; the screen polls its status and opens the file through a signed link. */
class RunDataExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $exportId, public int $tenantId) {}

    public function handle(ExportBuilder $builder, TenantContext $context): void
    {
        $tenant = $context->withoutScope(fn () => Tenant::find($this->tenantId));

        if (! $tenant) {
            return;
        }

        $context->withTenant($tenant, function () use ($builder) {
            $export = DataExport::find($this->exportId);
            $user = $export?->user_id ? User::find($export->user_id) : null;

            if (! $export || $export->status !== DataExport::QUEUED) {
                return;
            }

            if (! $user || ! $user->is_active) {
                $export->update(['status' => DataExport::FAILED, 'error' => 'The person who asked no longer has access.', 'finished_at' => now()]);

                return;
            }

            $export->update(['status' => DataExport::RUNNING]);

            try {
                $file = $builder->build($export, $user);
            } catch (\Throwable $e) {
                $reference = (string) Str::uuid();
                // The class and where, never the message: it can repeat a row, and a row is a customer.
                Log::error('export.failed', ['export' => $export->uuid, 'reference' => $reference, 'exception' => $e::class, 'at' => $e->getFile().':'.$e->getLine()]);
                $export->update(['status' => DataExport::FAILED, 'error' => 'Export failed. Reference: '.$reference, 'finished_at' => now()]);

                return;
            }

            $export->update($file + [
                'status' => DataExport::READY,
                'finished_at' => now(),
                'expires_at' => now()->addHours(DataExport::KEEP_HOURS),
            ]);
        });
    }
}
