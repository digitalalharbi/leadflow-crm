<?php

namespace App\Jobs;

use App\Domain\Git\RepositoryStateReducer;
use App\Events\RepositoryFeedUpdate;
use App\Listeners\TellSomebodyAboutTheRepository;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\RepositoryWebhookDelivery;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Turning one delivery into what the project screen shows.
 *
 * Queued because GitHub expects an answer within ten seconds and retries what
 * it does not get: a reducer that ran inside the request would turn a slow
 * database into a duplicate delivery, and then into a duplicate timeline.
 *
 * The payload travels on the job rather than through the database. It holds
 * every commit message and every author's email address, and none of it is
 * needed once the reducer has run — storing it would mean keeping a copy of
 * the customer's repository in our tables forever.
 */
class ProcessRepositoryDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(
        private readonly int $deliveryId,
        private readonly array $payload,
    ) {}

    public function handle(
        RepositoryStateReducer $reducer,
        TenantContext $context,
        TellSomebodyAboutTheRepository $teller,
    ): void {
        /*
         * Read without the tenant scope, which fails closed outside a request.
         * Scoped, a queued job would find no delivery at all and the whole
         * development view would stay empty with nothing in the log to say why.
         */
        $delivery = RepositoryWebhookDelivery::withoutGlobalScopes()->find($this->deliveryId);

        if (! $delivery) {
            return;
        }

        $repository = ProjectRepository::withoutGlobalScopes()
            ->with('tenant')
            ->find($delivery->project_repository_id);

        /*
         * Loaded without the tenant scope, like the tenant above. Eager-loading
         * the relation applies it, and outside a request it fails closed — the
         * project would come back null for a repository that plainly has one,
         * and nobody would ever be told the build broke.
         */
        $project = $repository
            ? Project::withoutGlobalScopes()->find($repository->project_id)
            : null;

        if (! $repository || ! $repository->tenant) {
            $delivery->forceFill([
                'status' => RepositoryWebhookDelivery::IGNORED,
                'error' => 'repository_unlinked',
                'processed_at' => now(),
            ])->save();

            return;
        }

        $context->withTenant($repository->tenant, function () use ($reducer, $repository, $delivery, $teller, $project) {
            try {
                $result = $reducer->apply($repository, $delivery->event, $this->payload);

                $delivery->forceFill([
                    'status' => $result['handled']
                        ? RepositoryWebhookDelivery::PROCESSED
                        : RepositoryWebhookDelivery::IGNORED,
                    'summary' => $result['summary'],
                    'processed_at' => now(),
                ])->save();

                /*
                 * Only when something actually changed. An `issues` delivery we
                 * ignore is not a reason to make every open Development tab
                 * refetch, and a feed that fires on nothing is one people turn
                 * off.
                 */
                /*
                 * Only things that happened in the last hour.
                 *
                 * The first sweep on a repository with history would otherwise
                 * tell everybody about a build that broke last March, and a
                 * notification about something nobody can act on is how people
                 * learn to ignore the one that matters.
                 */
                if ($project) {
                    foreach ($result['created'] ?? [] as $activity) {
                        if ($activity->happened_at?->greaterThan(now()->subHour())) {
                            $teller->handle($activity, $project);
                        }
                    }
                }

                if ($result['handled']) {
                    RepositoryFeedUpdate::dispatch(
                        $repository->tenant->uuid,
                        $project?->uuid ?? '',
                        $delivery->event,
                    );
                }
            } catch (\Throwable $e) {
                // The message can carry a branch name, a commit message or a
                // private repository's path, so the class and the line go to the
                // log and the delivery keeps a key the screen can translate.
                Log::error('github.delivery_failed', [
                    'delivery' => $delivery->delivery_id,
                    'event' => $delivery->event,
                    'exception' => $e::class,
                    'at' => $e->getFile().':'.$e->getLine(),
                ]);

                $delivery->forceFill([
                    'status' => RepositoryWebhookDelivery::FAILED,
                    'error' => 'processing_failed',
                    'processed_at' => now(),
                ])->save();

                throw $e;
            }
        });
    }
}
