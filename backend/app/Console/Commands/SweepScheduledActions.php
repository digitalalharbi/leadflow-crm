<?php

namespace App\Console\Commands;

use App\Jobs\RunScheduledAction;
use App\Models\ScheduledAction;
use Illuminate\Console\Command;

/**
 * Finds the waits whose timer was lost.
 *
 * A step is promised for a moment by a delayed job. If the queue was flushed, restarted without its
 * delayed set, or the job died before it could record anything, the step would stay "waiting" for ever.
 * Anything still pending a good while after its time is put back on the queue; the job itself checks the
 * lead again and a lock keeps two workers from running one step, so asking twice is harmless.
 */
class SweepScheduledActions extends Command
{
    protected $signature = 'leadflow:sweep-scheduled {--grace=10 : minutes past its time before a step counts as lost}';

    protected $description = 'Put back on the queue the scheduled steps whose timer was lost';

    /** A backlog is worked off in pieces, oldest first. */
    private const BATCH = 300;

    public function handle(): int
    {
        $found = 0;

        ScheduledAction::withoutGlobalScopes()
            ->where('status', ScheduledAction::PENDING)
            ->where('run_at', '<=', now()->subMinutes(max(1, (int) $this->option('grace'))))
            ->orderBy('run_at')
            ->limit(self::BATCH)
            ->get(['id'])
            ->each(function (ScheduledAction $row) use (&$found) {
                RunScheduledAction::dispatch($row->id);
                $found++;
            });

        $this->info("{$found} overdue step(s) put back on the queue.");

        return self::SUCCESS;
    }
}
