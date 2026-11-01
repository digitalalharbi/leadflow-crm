<?php

namespace App\Console\Commands;

use App\Models\DataExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes export files past their day: a spreadsheet of every lead's phone number is not something to keep lying
 * around on the server. Also fails exports stuck in the queue for more than an hour, so the screen stops waiting.
 */
class PruneDataExports extends Command
{
    protected $signature = 'leadflow:prune-exports';

    protected $description = 'Deletes expired export files and fails stuck exports.';

    public function handle(): int
    {
        $expired = 0;

        DataExport::withoutGlobalScopes()->where('status', DataExport::READY)->where('expires_at', '<', now())
            ->chunkById(200, function ($rows) use (&$expired) {
                foreach ($rows as $row) {
                    if ($row->path !== null) {
                        Storage::disk('local')->delete($row->path);
                    }
                    $row->forceFill(['status' => DataExport::EXPIRED, 'path' => null])->save();
                    $expired++;
                }
            });

        $stuck = DataExport::withoutGlobalScopes()->whereIn('status', [DataExport::QUEUED, DataExport::RUNNING])
            ->where('created_at', '<', now()->subHour())
            ->update(['status' => DataExport::FAILED, 'error' => 'The export did not finish in time.', 'finished_at' => now()]);

        $this->info("Expired {$expired}, failed {$stuck} stuck.");

        return self::SUCCESS;
    }
}
