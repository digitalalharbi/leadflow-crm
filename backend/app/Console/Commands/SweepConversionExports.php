<?php

namespace App\Console\Commands;

use App\Jobs\SendConversionExport;
use App\Models\ConversionExport;
use Illuminate\Console\Command;

/**
 * Pushes queued conversions at the platforms.
 *
 * Runs on a schedule rather than only on the outcome, because the interesting
 * case is the backlog: a workspace that wins deals for a month and then
 * connects its ad account should see that month uploaded, not discover that
 * every conversion was dropped because the integration did not exist yet.
 */
class SweepConversionExports extends Command
{
    protected $signature = 'leadflow:sweep-conversions {--limit=200}';

    protected $description = 'Send queued offline conversions to the ad platforms';

    public function handle(): int
    {
        $pending = ConversionExport::withoutGlobalScopes()
            ->sendable()
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->pluck('id');

        foreach ($pending as $id) {
            SendConversionExport::dispatch($id);
        }

        $this->info("Queued {$pending->count()} conversion(s) for upload.");

        return self::SUCCESS;
    }
}
