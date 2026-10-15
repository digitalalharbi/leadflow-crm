<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Proof that a worker is picking jobs up.
 *
 * A queue that accepts jobs and never runs them looks healthy from every angle
 * the web process has: Redis answers, `dispatch()` returns. The only evidence a
 * worker exists is a job that ran, so this is one — dispatched by the
 * scheduler's heartbeat and recording the moment it was executed.
 */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public const KEY = 'platform:queue_heartbeat';

    public function handle(): void
    {
        Cache::put(self::KEY, now()->timestamp, 3600);
    }
}
