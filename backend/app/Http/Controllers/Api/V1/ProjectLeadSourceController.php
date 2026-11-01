<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Permissions;
use App\Domain\LeadSources\LeadFormMappings;
use App\Domain\LeadSources\ProjectLeadSources;
use App\Http\Controllers\Controller;
use App\Models\LeadFormMapping;
use App\Models\Project;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Project → Lead Sources. Project-scoped by the query itself: another company's or another manager's project is simply not found. */
class ProjectLeadSourceController extends Controller
{
    public function __construct(private ProjectLeadSources $sources, private AuditLogger $audit) {}

    /** A long-standing role is not held by the route's permission, so each door asks: reading needs tracking.view, changing tracking.edit. */
    private function may(Request $request, string $permission): void
    {
        // Someone from outside the company (a broker, a client) has no business with its form ids and traffic, even on a project they can open.
        abort_if($request->user()->isExternal(), 403, 'Your role does not include this.');
        abort_unless(Permissions::has($request->user(), $permission), 403, 'Your role does not include this.');
    }

    public function index(Request $request, string $uuid)
    {
        $this->may($request, 'tracking.view');

        return ApiResponse::success($this->sources->for(Project::where('uuid', $uuid)->firstOrFail()));
    }

    public function compare(Request $request, string $uuid)
    {
        $this->may($request, 'tracking.view');
        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:365']])['days'] ?? 30);

        return ApiResponse::success(['days' => $days, 'pages' => $this->sources->compare(Project::where('uuid', $uuid)->firstOrFail(), $days)]);
    }

    public function mappings(Request $request, string $uuid)
    {
        $this->may($request, 'tracking.view');
        $project = Project::where('uuid', $uuid)->firstOrFail();

        return ApiResponse::success(['mappings' => LeadFormMapping::where('project_id', $project->id)->latest('id')->get()->map(fn ($m) => $this->shape($m))->values()]);
    }

    public function storeMapping(Request $request, string $uuid)
    {
        $this->may($request, 'tracking.edit');
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $data = $this->validated($request);

        // One form feeds one project: a form already tied elsewhere is moved on purpose, not by accident.
        if (LeadFormMapping::where('provider', $data['provider'])->where('form_ref', $data['form_ref'])->exists()) {
            return ApiResponse::error('This form is already tied to a project.', ['form_ref' => ['taken']], 422);
        }

        $mapping = LeadFormMapping::create($data + ['project_id' => $project->id, 'is_active' => $data['is_active'] ?? true, 'created_by' => $request->user()->id]);
        $this->audit->log('lead_form_mapping.created', $request->user(), $mapping, ['provider' => $mapping->provider, 'form' => $mapping->form_ref]);

        return ApiResponse::created(['mapping' => $this->shape($mapping)]);
    }

    public function updateMapping(Request $request, string $uuid, string $mapping)
    {
        $this->may($request, 'tracking.edit');
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $row = LeadFormMapping::where('project_id', $project->id)->where('uuid', $mapping)->firstOrFail();
        $data = $request->validate([
            'field_mapping' => ['sometimes', 'array', 'max:40'],
            'field_mapping.*' => ['string', 'max:80', $this->target()],
            'is_active' => ['sometimes', 'boolean'],
            'form_name' => ['sometimes', 'nullable', 'string', 'max:191'],
        ]);
        $row->update($data);
        $this->audit->log('lead_form_mapping.updated', $request->user(), $row, ['fields' => array_keys($data)]);

        return ApiResponse::success(['mapping' => $this->shape($row)]);
    }

    public function destroyMapping(Request $request, string $uuid, string $mapping)
    {
        $this->may($request, 'tracking.edit');
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $row = LeadFormMapping::where('project_id', $project->id)->where('uuid', $mapping)->firstOrFail();
        $this->audit->log('lead_form_mapping.deleted', $request->user(), $row, ['provider' => $row->provider, 'form' => $row->form_ref]);
        $row->delete();

        return ApiResponse::success(null, 'Removed.');
    }

    /** The project's own logo, on the same asset store the pages use. */
    public function logo(Request $request, string $uuid)
    {
        $this->may($request, 'tracking.edit');
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $data = $request->validate(['url' => ['nullable', 'string', 'max:500', 'regex:#^/api/v1/public/form-assets/[a-f0-9]{32}\.(png|jpg|webp|svg)$#']]);

        abort_if(filled($data['url'] ?? null) && ! Storage::disk('local')->exists('form-assets/'.basename($data['url'])), 422, 'Unknown image.');
        $project->forceFill(['logo_url' => $data['url'] ?? null])->save();
        $this->audit->log('project.logo_changed', $request->user(), $project);

        return ApiResponse::success(['logo_url' => $project->logo_url]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'provider' => ['required', Rule::in(LeadFormMapping::PROVIDERS)],
            'account_ref' => ['nullable', 'string', 'max:191'],
            'form_ref' => ['required', 'string', 'max:191'],
            'form_name' => ['nullable', 'string', 'max:191'],
            'field_mapping' => ['nullable', 'array', 'max:40'],
            'field_mapping.*' => ['string', 'max:80', $this->target()],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function target(): \Closure
    {
        return function ($attribute, $value, $fail) {
            if (! in_array($value, LeadFormMappings::TARGETS, true) && ! preg_match('/^custom:[a-z][a-z0-9_]{0,60}$/', (string) $value)) {
                $fail('Unknown target.');
            }
        };
    }

    private function shape(LeadFormMapping $m): array
    {
        return ['id' => $m->uuid, 'provider' => $m->provider, 'account_ref' => $m->account_ref, 'form_ref' => $m->form_ref, 'form_name' => $m->form_name, 'field_mapping' => $m->field_mapping ?? [], 'is_active' => (bool) $m->is_active, 'created_at' => $m->created_at?->toIso8601String()];
    }
}
