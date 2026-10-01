<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\IntegrationProvider;
use App\Http\Controllers\Controller;
use App\Http\Resources\IntegrationResource;
use App\Models\Integration;
use App\Models\WebhookEndpoint;
use App\Models\WebhookLog;
use App\Services\AuditLogger;
use App\Services\Integrations\ProviderRegistry;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class IntegrationController extends Controller
{
    public function __construct(
        private ProviderRegistry $registry,
        private AuditLogger $audit,
    ) {}

    /** Catalogue + this tenant's configured instances, for the Integrations page cards. */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Integration::class);

        $configured = Integration::with('webhookEndpoints')->get()->keyBy(fn ($i) => $i->provider->value);

        $catalogue = collect($this->registry->connectable())->map(function (IntegrationProvider $provider) use ($configured) {
            $instance = $configured->get($provider->value);
            $driver = $this->registry->get($provider);

            return [
                'provider' => $provider->value,
                'required_credentials' => $driver->requiredCredentials(),
                'status' => $this->resolveStatus($instance, $driver),
                'integration' => $instance ? new IntegrationResource($instance) : null,
            ];
        });

        return ApiResponse::success(['integrations' => $catalogue->values()]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Integration::class);

        $data = $request->validate([
            'provider' => ['required', Rule::in(array_map(fn ($p) => $p->value, $this->registry->connectable()))],
            'name' => ['nullable', 'string', 'max:120'],
            'credentials' => ['nullable', 'array'],
            'config' => ['nullable', 'array'],
            'field_mapping' => ['nullable', 'array'],
            'defaults' => ['nullable', 'array'],
        ]);

        $integration = Integration::updateOrCreate(
            ['provider' => $data['provider'], 'name' => $data['name'] ?? null],
            [
                'credentials' => $data['credentials'] ?? null,
                'config' => $data['config'] ?? null,
                'field_mapping' => $data['field_mapping'] ?? [],
                'defaults' => $data['defaults'] ?? null,
                'is_active' => true,
            ],
        );

        $driver = $this->registry->get($integration->provider);
        $integration->update(['status' => $this->resolveStatus($integration, $driver)]);

        $this->audit->log('integration.saved', $request->user(), $integration, [
            'provider' => $integration->provider->value,
        ]);

        return ApiResponse::created(['integration' => new IntegrationResource($integration->load('webhookEndpoints'))]);
    }

    public function update(Request $request, string $uuid)
    {
        $integration = Integration::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $integration);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'credentials' => ['nullable', 'array'],
            'config' => ['nullable', 'array'],
            'field_mapping' => ['nullable', 'array'],
            'defaults' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Merge credentials so an untouched secret is not wiped by a partial save.
        if (isset($data['credentials'])) {
            $data['credentials'] = array_filter(
                array_merge($integration->credentials ?? [], $data['credentials']),
                fn ($v) => filled($v),
            );
        }

        $integration->update($data);

        $driver = $this->registry->get($integration->provider);
        $integration->update(['status' => $this->resolveStatus($integration, $driver)]);

        $this->audit->log('integration.updated', $request->user(), $integration, [
            'fields' => array_keys($data),
        ]);

        return ApiResponse::success(['integration' => new IntegrationResource($integration->fresh('webhookEndpoints'))]);
    }

    public function destroy(Request $request, string $uuid)
    {
        $integration = Integration::where('uuid', $uuid)->firstOrFail();
        $this->authorize('delete', $integration);

        $this->audit->log('integration.deleted', $request->user(), $integration);
        $integration->delete();

        return ApiResponse::success(null, 'Integration removed.');
    }

    /** Issues a new endpoint. The plaintext secret is returned exactly once. */
    public function createWebhook(Request $request, string $uuid)
    {
        $integration = Integration::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $integration);

        $data = $request->validate(['label' => ['nullable', 'string', 'max:120']]);

        [$endpoint, $secret] = WebhookEndpoint::issue($integration->id, $data['label'] ?? null);

        $this->audit->log('integration.webhook_created', $request->user(), $integration);

        return ApiResponse::created([
            'endpoint' => [
                'id' => $endpoint->uuid,
                'key' => $endpoint->key,
                'label' => $endpoint->label,
                'url' => url("/api/v1/integrations/webhooks/{$endpoint->key}/leads"),
            ],
            'secret' => $secret,
            'notice' => 'Copy this secret now — it cannot be shown again.',
        ]);
    }

    public function rotateWebhookSecret(Request $request, string $uuid)
    {
        $endpoint = WebhookEndpoint::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $endpoint->integration ?? Integration::class);

        $secret = Str::random(48);
        $endpoint->update(['secret_hash' => Hash::make($secret)]);

        $this->audit->log('integration.webhook_secret_rotated', $request->user(), $endpoint);

        return ApiResponse::success([
            'secret' => $secret,
            'notice' => 'Copy this secret now — it cannot be shown again.',
        ]);
    }

    public function deleteWebhook(Request $request, string $uuid)
    {
        $endpoint = WebhookEndpoint::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $endpoint->integration ?? Integration::class);

        $endpoint->update(['is_active' => false]);
        $this->audit->log('integration.webhook_disabled', $request->user(), $endpoint);

        return ApiResponse::success(null, 'Endpoint disabled.');
    }

    /** Delivery log. Payloads stay hidden unless the viewer is an admin. */
    public function logs(Request $request)
    {
        $this->authorize('viewAny', Integration::class);

        $logs = WebhookLog::query()
            ->with('lead:id,uuid,full_name')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('provider'), fn ($q) => $q->where('provider', $request->input('provider')))
            ->orderByDesc('received_at')
            ->paginate(min((int) $request->input('per_page', 25), 100));

        return response()->json([
            'success' => true,
            'data' => collect($logs->items())->map(fn ($log) => [
                'id' => $log->uuid,
                'provider' => $log->provider,
                'status' => $log->status,
                'http_status' => $log->http_status,
                'error' => $log->error,
                'received_at' => $log->received_at?->toIso8601String(),
                'lead' => $log->lead ? ['id' => $log->lead->uuid, 'full_name' => $log->lead->full_name] : null,
                'payload' => $request->user()->isAdmin() ? $log->payload : null,
            ]),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
            'message' => null,
            'errors' => null,
        ]);
    }

    private function resolveStatus(?Integration $integration, $driver): string
    {
        if (! $integration) {
            return Integration::NOT_CONNECTED;
        }

        if ($integration->last_error) {
            return Integration::ERROR;
        }

        if (! $driver->isConfigured($integration)) {
            return Integration::CONFIGURATION_REQUIRED;
        }

        return $integration->is_active ? Integration::CONNECTED : Integration::NOT_CONNECTED;
    }
}
