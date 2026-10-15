<?php

namespace App\Console\Commands;

use App\Jobs\QueueHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Leaves proof that the scheduler is running, and asks the queue to.
 *
 * "The server is set up" is a claim the application cannot check about itself
 * from a request: an environment can be correctly configured and have no cron
 * entry and no worker. What it can observe is its own pulse, so this is
 * scheduled every minute and the readiness screen reads the timestamps. A
 * deployment is live when something has actually been running, not when it was
 * told to.
 */
class Heartbeat extends Command
{
    public const KEY = 'platform:scheduler_heartbeat';

    protected $signature = 'leadflow:heartbeat';

    protected $description = 'Record that the scheduler and the queue worker are running.';

    public function handle(): int
    {
        Cache::put(self::KEY, now()->timestamp, 3600);

        QueueHeartbeat::dispatch();

        return self::SUCCESS;
    }
}
