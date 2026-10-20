<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PropertyResource;
use App\Models\Project;
use App\Models\Property;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use App\Support\RealEstate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The units a project sells or lets.
 *
 * Reading is for everyone who can see the project; changing a unit is a manager's call, because
 * a unit's status decides what the matching engine will offer a customer.
 */
class PropertyController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /** Every unit in the workspace, filtered — what a rep searches while on the phone. */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Project::class);

        $filters = $request->validate([
            'project_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(RealEstate::PROPERTY_STATUSES)],
            'property_type' => ['nullable', Rule::in(RealEstate::PROPERTY_TYPES)],
            'transaction_type' => ['nullable', Rule::in(RealEstate::PROPERTY_TRANSACTIONS)],
            'city' => ['nullable', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'bedrooms' => ['nullable', 'integer', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0'],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Property::query()->with('project:id,uuid,name')->orderBy('project_id')->orderBy('code');
        $this->applyFilters($query, $filters);

        return ApiResponse::paginated($query->paginate($filters['per_page'] ?? 24), PropertyResource::class);
    }

    /** One project's units, with how many are in each state. */
    public function forProject(Request $request, string $projectUuid)
    {
        $project = Project::where('uuid', $projectUuid)->firstOrFail();
        $this->authorize('view', $project);

        $query = Property::query()->where('project_id', $project->id)->orderBy('code');
        $this->applyFilters($query, $request->only(['status', 'property_type', 'transaction_type', 'q']));

        $counts = Property::query()->where('project_id', $project->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return ApiResponse::success([
            'properties' => PropertyResource::collection($query->limit(500)->get()),
            'summary' => collect(RealEstate::PROPERTY_STATUSES)->mapWithKeys(fn (string $s) => [$s => (int) ($counts[$s] ?? 0)])->all(),
            'options' => RealEstate::options(),
        ]);
    }

    public function store(Request $request, string $projectUuid)
    {
        $project = Project::where('uuid', $projectUuid)->firstOrFail();
        $this->authorize('update', $project);

        $data = $this->validated($request, $project);
        $property = Property::create($data + ['project_id' => $project->id]);

        $this->audit->log('property.created', $request->user(), $property, ['after' => $property->only(['code', 'status', 'price_minor'])]);

        return ApiResponse::created(['property' => new PropertyResource($property->load('project:id,uuid,name'))]);
    }

    public function update(Request $request, string $uuid)
    {
        $property = Property::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $property->project);

        $before = $property->only(['code', 'status', 'price_minor']);
        $property->update($this->validated($request, $property->project, $property));

        $this->audit->log('property.updated', $request->user(), $property, ['before' => $before, 'after' => $property->only(['code', 'status', 'price_minor'])]);

        return ApiResponse::success(['property' => new PropertyResource($property->refresh()->load('project:id,uuid,name'))]);
    }

    public function destroy(Request $request, string $uuid)
    {
        $property = Property::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $property->project);

        $this->audit->log('property.deleted', $request->user(), $property, ['before' => $property->only(['code', 'status', 'price_minor'])]);
        $property->delete();

        return ApiResponse::success(null, 'Property deleted.');
    }

    /** @param  Builder<Property>  $query */
    private function applyFilters($query, array $f): void
    {
        $query
            ->when($f['project_id'] ?? null, fn ($q, $uuid) => $q->whereIn('project_id', Project::where('uuid', $uuid)->select('id')))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['property_type'] ?? null, fn ($q, $v) => $q->where('property_type', $v))
            ->when($f['transaction_type'] ?? null, fn ($q, $v) => $q->where('transaction_type', $v))
            ->when($f['city'] ?? null, fn ($q, $v) => $q->where('city', $v))
            ->when($f['district'] ?? null, fn ($q, $v) => $q->where('district', $v))
            ->when(isset($f['bedrooms']), fn ($q) => $q->where('bedrooms', (int) $f['bedrooms']))
            ->when($f['price_max'] ?? null, fn ($q, $v) => $q->where('price_minor', '<=', (int) round(((float) $v) * 100)))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('code', 'ilike', "%{$v}%")->orWhere('title', 'ilike', "%{$v}%")->orWhere('district', 'ilike', "%{$v}%")));
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, Project $project, ?Property $existing = null): array
    {
        $partial = $existing !== null;
        $sometimes = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'code' => [$sometimes, 'string', 'max:60', Rule::unique('properties', 'code')
                ->where('tenant_id', $project->tenant_id)->where('project_id', $project->id)->whereNull('deleted_at')->ignore($existing?->id)],
            'title' => ['nullable', 'string', 'max:160'],
            'property_type' => [$sometimes, Rule::in(RealEstate::PROPERTY_TYPES)],
            'transaction_type' => ['sometimes', Rule::in(RealEstate::PROPERTY_TRANSACTIONS)],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'city' => ['nullable', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:50'],
            'bathrooms' => ['nullable', 'integer', 'min:0', 'max:50'],
            'area_sqm' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'floor' => ['nullable', 'integer', 'min:-5', 'max:200'],
            'status' => ['sometimes', Rule::in(RealEstate::PROPERTY_STATUSES)],
            'features' => ['nullable', 'array', 'max:30'],
            'features.*' => ['string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (array_key_exists('price', $data)) {
            $data['price_minor'] = $data['price'] === null ? null : (int) round($data['price'] * 100);
            unset($data['price']);
        }

        return $data;
    }
}
