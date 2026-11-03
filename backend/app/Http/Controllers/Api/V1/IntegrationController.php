<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ingestion\IngestionInsights;
use App\Domain\Integrations\IntegrationHealthReport;
use App\Domain\Mail\AccountEmail;
use App\Enums\IntegrationProvider;
use App\Http\Controllers\Controller;
use App\Http\Resources\IntegrationResource;
use App\Jobs\ReconcileRepository;
use App\Jobs\SyncSheetSource;
use App\Models\Integration;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\SheetSource;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\WebhookLog;
use App\Services\AuditLogger;
use App\Services\Integrations\LeadPuller;
use App\Services\Integrations\ProviderRegistry;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IntegrationController extends Controller
{
    /**
     * Which integrations have ever actually worked.
     *
     * Implemented and externally verified are not the same claim. Every adapter
     * here has tests, and tests prove the code does what we think when the
     * other end behaves as we think — they cannot prove the other end exists.
     * So this is computed from evidence: something real came through, or it has
     * not yet.
     */
    /**
     * Sweep everything that is pulled rather than pushed.
     *
     * Only those: a source that arrives by webhook has nothing to sync, and a
     * button that appeared to sync one would be a button that looks like it did
     * something. The count comes back so the screen can say what was queued
     * instead of claiming success for an empty list.
     */
    public function syncAll(Request $request)
    {
        $this->authorize('viewAny', Integration::class);

        $sheets = SheetSource::where('is_active', true)->get();

        foreach ($sheets as $source) {
            SyncSheetSource::dispatch($source->id, 'manual');
        }

        $repositories = ProjectRepository::all();

        foreach ($repositories as $repository) {
            ReconcileRepository::dispatch($repository->id);
        }

        return ApiResponse::success([
            'sheets' => $sheets->count(),
            'repositories' => $repositories->count(),
        ], 'Sweep queued.');
    }

    public function health(Request $request, IntegrationHealthReport $report)
    {
        $this->authorize('viewAny', Integration::class);

        return ApiResponse::success(['integrations' => $report->all()]);
    }

    public function __construct(
        private ProviderRegistry $registry,
        private AuditLogger $audit,
    ) {}

    /** A field mapping may only target a field an inbound payload may set. */
    private function mappingRules(string $provider): array
    {
        return [
            'field_mapping' => ['nullable', 'array', 'max:100'],
            'field_mapping.*' => ['required', 'string', Rule::in($this->registry->get($provider)->mappableKeys())],
            'defaults' => ['nullable', 'array', 'max:50'],
        ];
    }

    /**
     * Defaults are a map, so the keys carry the risk. `project_id` and
     * `assigned_to` arrive as UUIDs and are translated to internal ids here,
     * under the tenant scope — a UUID from another tenant simply is not found.
     */
    private function normaliseDefaults(?array $defaults, string $provider): ?array
    {
        if (blank($defaults)) {
            return $defaults;
        }

        $allowed = $this->registry->get($provider)->defaultableKeys();
        $unknown = array_diff(array_keys($defaults), $allowed);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'defaults' => ['Unknown default field: '.implode(', ', $unknown).'.'],
            ]);
        }

        if (filled($defaults['project_id'] ?? null)) {
            $id = Str::isUuid((string) $defaults['project_id'])
                ? Project::where('uuid', $defaults['project_id'])->value('id')
                : null;

            if ($id === null) {
                throw ValidationException::withMessages([
                    'defaults.project_id' => ['This project does not exist in your organisation.'],
                ]);
            }

            $defaults['project_id'] = $id;
        }

        if (filled($defaults['assigned_to'] ?? null)) {
            $id = Str::isUuid((string) $defaults['assigned_to'])
                ? User::where('tenant_id', app(TenantContext::class)->id())
                    ->where('uuid', $defaults['assigned_to'])
                    ->where('is_active', true)
                    ->value('id')
                : null;

            if ($id === null) {
                throw ValidationException::withMessages([
                    'defaults.assigned_to' => ['This user is not available in your organisation.'],
                ]);
            }

            $defaults['assigned_to'] = $id;
        }

        return $defaults;
    }

    /** Catalogue + this tenant's configured instances, for the Integrations page cards. */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Integration::class);
        // Webhook keys and errors of the whole company.
        abort_if($request->user()->isLimited(), 403, 'Your role does not include this.');

        $configured = Integration::with('webhookEndpoints')->get()->keyBy(fn ($i) => $i->provider->value);

        $catalogue = collect($this->registry->connectable())->map(function (IntegrationProvider $provider) use ($configured) {
            $instance = $configured->get($provider->value);
            $driver = $this->registry->get($provider);

            return [
                'provider' => $provider->value,
                'required_credentials' => $driver->requiredCredentials(),
                // Whether this source is polled or calls us. The screen needs
                // it to decide between offering a webhook URL and offering a
                // "sync now" button; they are not the same integration at all.
                'pulls_leads' => $driver->pullsLeads(),
                'status' => $this->resolveStatus($instance, $driver),
                'integration' => $instance ? new IntegrationResource($instance) : null,
            ];
        });

        return ApiResponse::success(['integrations' => $catalogue->values()]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Integration::class);

        $provider = $request->input('provider');

        $data = $request->validate(array_merge([
            'provider' => ['required', Rule::in(array_map(fn ($p) => $p->value, $this->registry->connectable()))],
            'name' => ['nullable', 'string', 'max:120'],
            'credentials' => ['nullable', 'array', 'max:20'],
            'config' => ['nullable', 'array', 'max:50'],
        ], is_string($provider) && in_array($provider, $this->registry->keys(), true)
            ? $this->mappingRules($provider)
            : ['field_mapping' => ['nullable', 'array'], 'defaults' => ['nullable', 'array']]));

        $data['defaults'] = $this->normaliseDefaults($data['defaults'] ?? null, $data['provider']);

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
        $this->guardAgainstDedicatedProvider($integration);
        $this->authorize('update', $integration);

        $data = $request->validate(array_merge([
            'name' => ['nullable', 'string', 'max:120'],
            'credentials' => ['nullable', 'array', 'max:20'],
            'config' => ['nullable', 'array', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
        ], $this->mappingRules($integration->provider->value)));

        if (array_key_exists('defaults', $data)) {
            $data['defaults'] = $this->normaliseDefaults($data['defaults'], $integration->provider->value);
        }

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
        $this->guardAgainstDedicatedProvider($integration);
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

        abort_if($endpoint->integration === null, 404, 'Resource not found.');
        $this->authorize('update', $endpoint->integration);

        $secret = Str::random(48);
        $endpoint->update(['secret_hash' => Hash::make($secret)]);

        $this->audit->log('integration.webhook_secret_rotated', $request->user(), $endpoint);
        app(AccountEmail::class)->securityNotice($request->user(), 'webhook_secret_rotated');

        return ApiResponse::success([
            'secret' => $secret,
            'notice' => 'Copy this secret now — it cannot be shown again.',
        ]);
    }

    public function deleteWebhook(Request $request, string $uuid)
    {
        $endpoint = WebhookEndpoint::where('uuid', $uuid)->firstOrFail();

        abort_if($endpoint->integration === null, 404, 'Resource not found.');
        $this->authorize('update', $endpoint->integration);

        $endpoint->update(['is_active' => false]);
        $this->audit->log('integration.webhook_disabled', $request->user(), $endpoint);

        return ApiResponse::success(null, 'Endpoint disabled.');
    }

    /**
     * Whether leads are still arriving.
     *
     * The list of connected sources above this cannot answer it: a form that
     * stopped posting eleven days ago still shows a green badge and a valid
     * secret. The only evidence of arrival is arrivals.
     *
     * Readable by anyone who can see the integrations — the person who notices
     * a quiet week is rarely the person who set the webhook up.
     */
    public function ingestion(Request $request, IngestionInsights $insights)
    {
        $this->authorize('viewAny', Integration::class);

        $days = min(90, max(7, (int) $request->integer('days', 30)));

        return ApiResponse::success([
            'summary' => $insights->summary($days),
            'daily' => $insights->daily($days),
            'by_provider' => $insights->byProvider($days),
        ]);
    }

    /**
     * Ask this source for its leads now, rather than waiting for the poll.
     *
     * The button that makes a pasted access token mean something. Without it a
     * workspace saves its credentials, sees nothing change, and has no way to
     * tell a wrong token from a quiet afternoon until the next scheduled run —
     * so the first thing anybody would do is paste it again.
     */
    public function sync(Request $request, string $uuid, LeadPuller $puller, TenantContext $context)
    {
        $integration = Integration::where('uuid', $uuid)->firstOrFail();

        $this->authorize('update', $integration);

        $tenant = $context->get();

        if ($tenant === null) {
            return ApiResponse::error('No workspace in context.', null, 422);
        }

        $result = $puller->pull($tenant, $integration->fresh());

        $this->audit->log('integration.synced', $request->user(), $integration, [
            'provider' => $integration->provider?->value,
            'fetched' => $result['fetched'],
        ]);

        return ApiResponse::success(
            ['result' => $result, 'integration' => new IntegrationResource($integration->fresh())],
            $result['error'] === null ? null : 'The source could not be reached.'
        );
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

    /**
     * Some providers have a screen of their own, and the generic one does not
     * know how to handle them.
     *
     * WhatsApp is the case today: PATCH here threw on a field mapping that does
     * not exist, and DELETE succeeded — which bypassed the proper disconnect,
     * left the credentials on the row, and wrote the wrong action to the audit
     * trail. From here that integration simply is not found.
     */
    private function guardAgainstDedicatedProvider(Integration $integration): void
    {
        abort_if(
            $integration->provider === IntegrationProvider::Whatsapp,
            404,
            'Resource not found.',
        );
    }
}
