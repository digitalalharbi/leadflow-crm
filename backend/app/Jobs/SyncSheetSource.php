<?php

namespace App\Jobs;

use App\Domain\Sheets\SheetImporter;
use App\Models\SheetSource;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One pass over one sheet.
 *
 * A job per source rather than one that walks them all: a workspace whose grant
 * was revoked must not stop every other workspace's sheet from syncing, and a
 * first import of several thousand rows must not hold the queue behind it.
 */
class SyncSheetSource implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [60];

    public function __construct(
        private readonly int $sourceId,
        private readonly string $trigger = 'scheduled',
    ) {}

    public function handle(SheetImporter $importer, TenantContext $context): void
    {
        /*
         * Without the tenant scope, which fails closed outside a request. Scoped,
         * a queued sync would find no source at all and every sheet in the
         * product would quietly stop importing with nothing in the log.
         */
        $source = SheetSource::withoutGlobalScopes()->with('tenant')->find($this->sourceId);

        if (! $source || ! $source->tenant || ! $source->is_active) {
            return;
        }

        $context->withTenant($source->tenant, function () use ($importer, $source) {
            $importer->sync($source, $source->tenant, $this->trigger);
        });
    }
}
