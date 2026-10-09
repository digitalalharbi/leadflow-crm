<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileRepository;
use App\Models\ProjectRepository;
use Illuminate\Console\Command;

/**
 * The scheduled repair pass over every linked repository.
 *
 * Deliberately a fan-out of one job per repository rather than one long job:
 * a workspace whose token has expired must not stop every other workspace's
 * sweep, and a queue can spread a hundred of these across workers while a
 * single job would do them one at a time behind the slowest.
 */
class ReconcileRepositoriesCommand extends Command
{
    protected $signature = 'leadflow:reconcile-repositories
                            {--repository= : a single repository uuid, for a manual repair}';

    protected $description = 'Catch up on repository activity that never arrived by webhook.';

    public function handle(): int
    {
        $query = ProjectRepository::withoutGlobalScopes()->whereNotNull('integration_id');

        if ($uuid = $this->option('repository')) {
            $query->where('uuid', $uuid);
        }

        $count = 0;

        $query->orderBy('id')->chunkById(100, function ($repositories) use (&$count) {
            foreach ($repositories as $repository) {
                ReconcileRepository::dispatch($repository->id);
                $count++;
            }
        });

        $this->info($count === 0
            ? 'No linked repositories to sweep.'
            : "Queued {$count} repository sweep(s).");

        return self::SUCCESS;
    }
}
