<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tracking\Destinations\DestinationKind;
use App\Http\Controllers\Controller;
use App\Models\DestinationDelivery;
use App\Models\Project;
use App\Models\TrackingDestination;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Where a workspace's events may go. A manager's call: what leaves, and to whom.
 *
 * The credential goes in and never comes out — a destination answers only with which credentials it holds. Every
 * identifier is checked for the shape of the real thing, because it is injected into a page or called from a server.
 */
class TrackingDestinationController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function catalogue()
    {
        $this->authorize('viewAny', TrackingDestination::class);

        return ApiResponse::success(['kinds' => array_map(fn (DestinationKind $k) => [
            'kind' => $k->value, 'label' => $k->label(), 'browser' => $k->browser(), 'server' => $k->server(),
            'config' => $k->config(), 'credentials' => array_merge($k->credentials(), $k->optionalCredentials()),
            'conversion_platform' => $k->conversionPlatform(),
        ], DestinationKind::cases()), 'events' => DestinationKind::EVENTS, 'default_events' => DestinationKind::DEFAULT_EVENTS]);
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', TrackingDestination::class);

        $f = $request->validate(['project_id' => ['nullable', 'uuid'], 'kind' => ['nullable', Rule::in(DestinationKind::values())]]);

        $rows = TrackingDestination::query()->with('project:id,uuid,name')
            ->when($f['project_id'] ?? null, fn ($q, $uuid) => $q->whereIn('project_id', Project::where('uuid', $uuid)->select('id')))
            ->when($f['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->latest('id')->limit(100)->get();

        $stats = $this->deliveryStats($rows->pluck('id')->all());

        return ApiResponse::success(['destinations' => $rows->map(fn (TrackingDestination $d) => $this->shape($d, $stats[$d->id] ?? []))->values()]);
    }

    public function show(string $uuid)
    {
        $destination = TrackingDestination::where('uuid', $uuid)->with('project:id,uuid,name')->firstOrFail();
        $this->authorize('view', $destination);

        return ApiResponse::success(['destination' => $this->shape($destination, $this->deliveryStats([$destination->id])[$destination->id] ?? [])]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', TrackingDestination::class);

        $data = $this->validated($request);
        $destination = TrackingDestination::create($data + [
            'is_enabled' => false, 'send_value' => true, 'hash_identifiers' => true, 'consent_mode' => 'required', 'created_by' => $request->user()->id,
        ]);
        $this->audit->log('tracking_destination.created', $request->user(), $destination, ['after' => ['kind' => $destination->kind, 'name' => $destination->name, 'enabled' => $destination->is_enabled, 'credentials' => array_keys((array) $destination->credentials)]]);

        return ApiResponse::created(['destination' => $this->shape($destination->load('project:id,uuid,name'), [])]);
    }

    public function update(Request $request, string $uuid)
    {
        $destination = TrackingDestination::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $destination);

        $before = ['name' => $destination->name, 'enabled' => $destination->is_enabled, 'events' => $destination->events];
        $destination->update($this->validated($request, $destination, partial: true));
        $this->audit->log('tracking_destination.updated', $request->user(), $destination, ['before' => $before, 'after' => ['name' => $destination->name, 'enabled' => $destination->is_enabled, 'events' => $destination->events]]);

        return ApiResponse::success(['destination' => $this->shape($destination->refresh()->load('project:id,uuid,name'), $this->deliveryStats([$destination->id])[$destination->id] ?? [])]);
    }

    public function destroy(Request $request, string $uuid)
    {
        $destination = TrackingDestination::where('uuid', $uuid)->firstOrFail();
        $this->authorize('delete', $destination);

        $this->audit->log('tracking_destination.deleted', $request->user(), $destination, ['before' => ['kind' => $destination->kind, 'name' => $destination->name]]);
        $destination->delete();

        return ApiResponse::success(null, 'Destination deleted.');
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, ?TrackingDestination $current = null, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'kind' => [$partial ? 'prohibited' : 'required', Rule::in(DestinationKind::values())],
            'name' => [$req, 'string', 'max:120'],
            'project_id' => ['nullable', 'uuid'],
            'is_enabled' => ['sometimes', 'boolean'],
            'config' => ['sometimes', 'array', 'max:10'],
            'config.*' => ['nullable', 'string', 'max:500'],
            'credentials' => ['sometimes', 'array', 'max:6'],
            'credentials.*' => ['nullable', 'string', 'max:500'],
            'events' => ['sometimes', 'array', 'max:'.count(DestinationKind::EVENTS)],
            'events.*' => ['string', Rule::in(DestinationKind::EVENTS)],
            'send_value' => ['sometimes', 'boolean'],
            'hash_identifiers' => ['sometimes', 'boolean'],
            'consent_mode' => ['sometimes', Rule::in(['required', 'unless_denied'])],
        ]);

        if (array_key_exists('project_id', $data)) {
            $data['project_id'] = blank($data['project_id']) ? null : Project::where('uuid', $data['project_id'])->value('id');
            abort_if($data['project_id'] === null && filled($request->input('project_id')), 404, 'Resource not found.');
        }

        $kind = $current?->destinationKind() ?? DestinationKind::from($data['kind']);

        if (array_key_exists('config', $data)) {
            $data['config'] = array_intersect_key(array_map('trim', $data['config']), array_flip($kind->config() + ['test_event_code']));
            $errors = $kind->configErrors($data['config']);

            if ($errors !== []) {
                throw ValidationException::withMessages(['config' => collect($errors)->map(fn ($why, $key) => "{$key}: {$why}")->values()->all()]);
            }
        } elseif ($current === null) {
            throw ValidationException::withMessages(['config' => ['config: required']]);
        }

        if (array_key_exists('credentials', $data)) {
            // A credential left blank in an update keeps the one already held: the form never has it to send back.
            $held = (array) ($current?->credentials ?? []);
            $given = array_filter($data['credentials'], fn ($v) => filled($v));
            $data['credentials'] = array_intersect_key($given + $held, array_flip(array_merge($kind->credentials(), $kind->optionalCredentials())));
        }

        unset($data['kind']);

        return $data + ($current === null ? ['kind' => $kind->value] : []);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int,array<string,int>>
     */
    private function deliveryStats(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DestinationDelivery::query()->whereIn('destination_id', $ids)->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('destination_id, status, count(*) as n')->groupBy('destination_id', 'status')->get()
            ->groupBy('destination_id')->map(fn ($rows) => $rows->pluck('n', 'status')->map(fn ($n) => (int) $n)->all())->all();
    }

    /**
     * @param  array<string,int>  $stats
     * @return array<string,mixed>
     */
    private function shape(TrackingDestination $d, array $stats): array
    {
        $kind = $d->destinationKind();

        return [
            'id' => $d->uuid,
            'kind' => $d->kind,
            'label' => $kind->label(),
            'name' => $d->name,
            'project' => $d->project ? ['id' => $d->project->uuid, 'name' => $d->project->name] : null,
            'is_enabled' => (bool) $d->is_enabled,
            'config' => (array) $d->config,
            // Which credentials it holds — never what they are.
            'credentials_set' => array_keys(array_filter((array) $d->credentials, fn ($v) => filled($v))),
            'events' => $d->allowedEvents(),
            'send_value' => (bool) $d->send_value,
            'hash_identifiers' => (bool) $d->hash_identifiers,
            'consent_mode' => $d->consent_mode,
            'browser' => $kind->browser(),
            'server' => $kind->server(),
            'readiness' => $d->readiness(),
            'offline_conversions' => $d->offlineConversions(),
            'last_event_at' => $d->last_event_at?->toIso8601String(),
            'last_error' => $d->last_error,
            'deliveries_7d' => $stats,
        ];
    }
}
