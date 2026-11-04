<?php

namespace Tests\Feature;

use App\Jobs\SyncSheetSource;
use Tests\TestCase;

/** The numbers production depends on: each caused a job to run twice, or to fail on a row that was about to exist. */
class QueueConfigurationTest extends TestCase
{
    public function test_no_job_reaches_a_worker_before_its_transaction_commits(): void
    {
        $this->assertTrue(config('queue.connections.redis.after_commit'));
        $this->assertTrue(config('queue.connections.redis-heavy.after_commit'));
    }

    public function test_a_job_is_not_handed_out_again_while_its_worker_may_still_be_running_it(): void
    {
        $production = config('horizon.environments.production');

        foreach ($production as $name => $supervisor) {
            $timeout = $supervisor['timeout'] ?? config('horizon.defaults.supervisor-1.timeout');
            $connection = $supervisor['connection'] ?? config('horizon.defaults.supervisor-1.connection');

            $this->assertGreaterThan($timeout, config("queue.connections.{$connection}.retry_after"), $name);
        }
    }

    public function test_a_sheet_sync_runs_on_the_heavy_worker(): void
    {
        $this->assertSame('heavy', (new SyncSheetSource(1))->queue);
    }
}
