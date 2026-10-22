<?php

namespace App\Console\Commands;

use App\Jobs\SyncSheetSource;
use App\Models\SheetSource;
use Illuminate\Console\Command;

/**
 * The scheduled pass over every connected sheet.
 *
 * This is the normal way leads arrive from a sheet. "Sync now" exists for the
 * moment somebody has just fixed a mapping and wants to see it work — if it
 * becomes the way the product is used, the schedule is broken and the fix is
 * the schedule, not the button.
 */
class SyncSheetSourcesCommand extends Command
{
    protected $signature = 'leadflow:sync-sheets {--source= : one source uuid}';

    protected $description = 'Import new rows from every connected Google Sheet.';

    public function handle(): int
    {
        $query = SheetSource::withoutGlobalScopes()->where('is_active', true);

        if ($uuid = $this->option('source')) {
            $query->where('uuid', $uuid);
        }

        $count = 0;

        $query->orderBy('id')->chunkById(100, function ($sources) use (&$count) {
            foreach ($sources as $source) {
                SyncSheetSource::dispatch($source->id);
                $count++;
            }
        });

        $this->info($count === 0 ? 'No sheets connected.' : "Queued {$count} sheet sync(s).");

        return self::SUCCESS;
    }
}
