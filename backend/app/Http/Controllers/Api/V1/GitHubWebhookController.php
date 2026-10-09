<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Git\GitHub\GitHubSignature;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessRepositoryDelivery;
use App\Models\ProjectRepository;
use App\Models\RepositoryWebhookDelivery;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Where GitHub tells مهتم what happened.
 *
 * Public, like the lead ingestion endpoint, and defensive for the same reasons:
 * the workspace comes from the key in the URL and never from the body, the
 * signature is checked against that workspace's own secret before anything is
 * read, the body is size-capped, every delivery is recorded whether it was
 * accepted or not, and a rejection says nothing about whether the key exists.
 *
 * It does almost nothing itself. GitHub expects an answer in ten seconds and
 * retries what it does not get, so the work happens on a queue and this returns
 * as soon as the delivery is stored.
 */
class GitHubWebhookController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(Request $request, string $key)
    {
        $maxBytes = (int) config('integrations.webhooks.max_body_bytes', 1048576);
        $raw = $request->getContent();

        if (strlen($raw) > $maxBytes) {
            return ApiResponse::error('Payload too large.', null, 413);
        }

        $delivery = (string) $request->header('X-GitHub-Delivery', '');
        $event = (string) $request->header('X-GitHub-Event', '');

        // Unscoped: there is no tenant context yet, the key establishes it.
        $mapping = $this->context->withoutScope(
            fn () => ProjectRepository::where('webhook_key', $key)->first()
        );

        if (! $mapping || blank($delivery) || blank($event)) {
            // Deliberately the same answer as a bad signature: a probe must not
            // be able to tell a real key from an invented one.
            return ApiResponse::error('Invalid endpoint or signature.', null, 401);
        }

        $valid = GitHubSignature::matches(
            $raw,
            $request->header('X-Hub-Signature-256'),
            $mapping->webhook_secret,
        );

        if (! $valid) {
            $this->context->withTenant($mapping->tenant, function () use ($mapping, $delivery, $event) {
                $mapping->increment('signature_failures');

                /*
                 * Recorded, not discarded. A run of these is either a secret
                 * that was rotated on one side only or somebody trying it on,
                 * and the health screen can only show the difference if the
                 * failures are counted.
                 */
                RepositoryWebhookDelivery::updateOrCreate(
                    ['delivery_id' => $delivery],
                    [
                        'project_repository_id' => $mapping->id,
                        'event' => $event,
                        'signature_valid' => false,
                        'status' => RepositoryWebhookDelivery::REJECTED,
                        'received_at' => now(),
                    ],
                );
            });

            Log::warning('github.webhook_signature_failed', [
                'repository' => $mapping->uuid,
                'event' => $event,
            ]);

            return ApiResponse::error('Invalid endpoint or signature.', null, 401);
        }

        $payload = $request->json()->all();
        $action = $payload['action'] ?? null;

        $stored = $this->context->withTenant($mapping->tenant, function () use ($mapping, $delivery, $event, $action) {
            /*
             * The delivery id is unique, so a redelivery GitHub makes because it
             * thinks the first failed is recognised rather than replayed — which
             * is what stops one merge appearing twice on a timeline.
             */
            $existing = RepositoryWebhookDelivery::where('delivery_id', $delivery)->first();

            if ($existing) {
                return null;
            }

            $mapping->forceFill(['last_webhook_at' => now()])->saveQuietly();

            return RepositoryWebhookDelivery::create([
                'project_repository_id' => $mapping->id,
                'delivery_id' => $delivery,
                'event' => $event,
                'action' => $action,
                'signature_valid' => true,
                'status' => RepositoryWebhookDelivery::RECEIVED,
                'received_at' => now(),
            ]);
        });

        if ($stored === null) {
            // Already seen. GitHub only needs to stop retrying.
            return ApiResponse::success(['status' => 'duplicate']);
        }

        /*
         * The payload travels on the job rather than being stored: the reducer
         * needs it once, and this table would otherwise hold every commit
         * message and author email address in the repository forever.
         */
        ProcessRepositoryDelivery::dispatch($stored->id, $payload);

        return ApiResponse::success(['status' => 'accepted']);
    }
}
