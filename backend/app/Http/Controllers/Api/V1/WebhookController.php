<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\IntegrationProvider;
use App\Http\Controllers\Controller;
use App\Models\WebhookEndpoint;
use App\Models\WebhookLog;
use App\Services\Integrations\ProviderRegistry;
use App\Services\Leads\LeadIngestionService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public ingestion endpoint — the one route in the system that runs without a
 * session. Everything about it is defensive:
 *
 *  - the tenant comes from the endpoint key, never from the payload;
 *  - the shared secret is hashed and compared in constant time;
 *  - the body is size-capped before it is parsed;
 *  - every attempt is logged, accepted or not;
 *  - a failure never leaks whether a key exists.
 */
class WebhookController extends Controller
{
    public function __construct(
        private LeadIngestionService $ingestion,
        private ProviderRegistry $providers,
        private TenantContext $context,
    ) {}

    public function receive(Request $request, string $key)
    {
        $maxBytes = config('integrations.webhooks.max_body_bytes');

        if (strlen($request->getContent()) > $maxBytes) {
            return ApiResponse::error('Payload too large.', null, 413);
        }

        // Unscoped lookup: there is no tenant context yet, the key establishes it.
        $endpoint = $this->context->withoutScope(
            fn () => WebhookEndpoint::with('integration')->where('key', $key)->where('is_active', true)->first()
        );

        if (! $endpoint) {
            // Deliberately identical to a bad-secret response.
            return ApiResponse::error('Invalid endpoint or secret.', null, 401);
        }

        $secret = $request->header('X-Webhook-Secret')
            ?? $request->bearerToken()
            ?? $request->input('secret');

        if (! $secret || ! $endpoint->verifySecret($secret)) {
            $this->log($endpoint, 'rejected', 401, $request, null, 'Invalid secret');

            return ApiResponse::error('Invalid endpoint or secret.', null, 401);
        }

        $tenant = $this->context->withoutScope(fn () => $endpoint->tenant()->first());

        if (! $tenant || ! $tenant->is_active) {
            $this->log($endpoint, 'rejected', 403, $request, null, 'Tenant inactive');

            return ApiResponse::error('This endpoint is not active.', null, 403);
        }

        $integration = $endpoint->integration;
        $providerKey = $integration?->provider?->value ?? IntegrationProvider::GenericWebhook->value;

        $payload = $request->except(['secret']);

        try {
            $provider = $this->providers->get($providerKey);
            $incoming = $provider->toIncomingLead($payload, $integration);

            $result = $this->ingestion->ingest($tenant, $incoming);
        } catch (\Throwable $e) {
            Log::error('webhook.ingest_failed', [
                'endpoint' => $endpoint->uuid,
                'provider' => $providerKey,
                'error' => $e->getMessage(),
            ]);

            $this->log($endpoint, 'failed', 500, $request, null, $e->getMessage());

            return ApiResponse::error('Could not process this lead.', null, 500);
        }

        if (! $result->wasAccepted()) {
            $this->log($endpoint, 'rejected', 422, $request, null, $result->reason);

            return ApiResponse::error($result->reason ?? 'Lead rejected.', null, 422);
        }

        $this->context->withTenant($tenant, function () use ($endpoint, $integration) {
            $endpoint->increment('received_count');
            $endpoint->forceFill(['last_received_at' => now()])->saveQuietly();
            $integration?->increment('leads_received');
        });

        $this->log(
            $endpoint,
            $result->isDuplicate() ? 'duplicate' : 'accepted',
            200,
            $request,
            $result->lead?->id,
        );

        return ApiResponse::success([
            'lead_id' => $result->lead?->uuid,
            'outcome' => $result->outcome,
        ], $result->isDuplicate()
            ? 'Existing lead updated and marked as re-engaged.'
            : 'Lead created.');
    }

    /**
     * Meta's webhook subscription handshake. Responds with hub.challenge when
     * the verify token matches.
     */
    public function verify(Request $request, string $key)
    {
        $expected = config('integrations.meta.verify_token');

        if ($expected && hash_equals($expected, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200)
                ->header('Content-Type', 'text/plain');
        }

        return ApiResponse::error('Verification failed.', null, 403);
    }

    private function log(
        WebhookEndpoint $endpoint,
        string $status,
        int $httpStatus,
        Request $request,
        ?int $leadId = null,
        ?string $error = null,
    ): void {
        try {
            $this->context->withoutScope(function () use ($endpoint, $status, $httpStatus, $request, $leadId, $error) {
                WebhookLog::create([
                    'tenant_id' => $endpoint->tenant_id,
                    'webhook_endpoint_id' => $endpoint->id,
                    'lead_id' => $leadId,
                    'provider' => $endpoint->integration?->provider?->value ?? 'generic_webhook',
                    'status' => $status,
                    'http_status' => $httpStatus,
                    'ip' => $request->ip(),
                    'payload' => $this->redactPayload($request->except(['secret'])),
                    'error' => $error,
                    'received_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            // Logging must never break ingestion.
            Log::warning('webhook.log_failed', ['error' => $e->getMessage()]);
        }
    }

    /** Never persist a shared secret or token that happened to be in the body. */
    private function redactPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (preg_match('/secret|token|password|authorization/i', (string) $key)) {
                $payload[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $payload[$key] = $this->redactPayload($value);
            }
        }

        return $payload;
    }
}
