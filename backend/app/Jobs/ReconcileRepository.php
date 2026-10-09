<?php

namespace App\Jobs;

use App\Domain\Git\GitHub\GitHubClient;
use App\Domain\Git\GitProviderRegistry;
use App\Domain\Git\RepositoryStateReducer;
use App\Models\Integration;
use App\Models\ProjectRepository;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Catching what the webhooks missed.
 *
 * Webhooks are the primary path and this is the repair. Things that make a
 * delivery vanish and leave no trace: the queue being down for ten minutes, a
 * secret rotated on one side, GitHub disabling a hook after too many failures,
 * a deploy during a deploy. None of them announce themselves, and all of them
 * end with a project screen that is quietly wrong.
 *
 * Incremental, not a resync. It asks "what changed since the cursor" and writes
 * through the same reducer the webhooks use, so the repair cannot disagree with
 * the thing it is repairing. A full sweep every ten minutes across every
 * workspace would spend the hourly budget by mid-morning and still be behind.
 */
class ReconcileRepository implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [30];

    public function __construct(private readonly int $repositoryId) {}

    public function handle(
        GitProviderRegistry $providers,
        RepositoryStateReducer $reducer,
        GitHubClient $client,
        TenantContext $context,
    ): void {
        $repository = ProjectRepository::withoutGlobalScopes()
            ->with('tenant')
            ->find($this->repositoryId);

        if (! $repository || ! $repository->tenant) {
            return;
        }

        /*
         * Loaded without the tenant scope, which fails closed outside a
         * request. Eager-loading the relation here would apply it, find no
         * connection for a repository that plainly has one, and the sweep would
         * return quietly having decided the workspace was disconnected — which
         * is indistinguishable from working correctly.
         */
        $connection = Integration::withoutGlobalScopes()->find($repository->integration_id);

        if (! $connection || blank($connection->credentials['access_token'] ?? null)) {
            // Nothing to do and nothing wrong: a workspace that disconnected
            // keeps its history and stops being swept.
            return;
        }

        /*
         * Read the budget before spending any of it. A sweep that starts with
         * nothing left spends its whole run collecting 403s, and the next
         * person to open a screen pays for it.
         */
        if ($client->shouldHoldOff($connection)) {
            $this->note($repository, 'rate_limited');

            return;
        }

        $context->withTenant($repository->tenant, function () use ($providers, $reducer, $repository, $connection) {
            $since = $repository->sync_cursor;

            try {
                $result = $providers->get($repository->provider)->changesSince($connection, $repository, $since);
            } catch (\Throwable $e) {
                Log::warning('github.reconcile_failed', [
                    'repository' => $repository->uuid,
                    'exception' => $e::class,
                ]);

                $this->note($repository, 'sync_failed');

                return;
            }

            // Whatever was readable is still worth writing: a sweep that could
            // not reach the pull requests but did see a failed deployment
            // should still show the failed deployment.
            foreach ($result->changes as $change) {
                $reducer->apply($repository, $change['event'], $change['payload']);
            }

            /*
             * The cursor moves only on a run that saw everything, and to the
             * time the run *started* rather than now: anything that happened
             * while it was fetching would otherwise fall into the gap between
             * the two and never be looked at again.
             *
             * A partial read leaves the cursor where it was. Advancing it would
             * skip the window it failed to see — permanently, and silently,
             * because the next sweep starts after it.
             */
            $repository->forceFill($result->complete()
                ? ['sync_cursor' => now()->subMinute(), 'last_sync_at' => now(), 'last_error' => null]
                : ['last_sync_at' => now(), 'last_error' => $result->error])->save();
        });
    }

    private function note(ProjectRepository $repository, string $error): void
    {
        $repository->forceFill(['last_error' => $error, 'last_sync_at' => now()])->save();
    }
}
