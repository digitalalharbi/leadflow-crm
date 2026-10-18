<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The businesses inside the holding, and how each is doing.
 *
 * Reading is for everyone, narrowed to the companies they may see; shaping is
 * an admin's. The numbers are computed from leads through their projects — the
 * one rule for which company a lead belongs to — in a single grouped pass, not
 * a count per card.
 */
class CompanyController extends Controller
{
    public function __construct(private CompanyContext $context) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Company::class);

        // The overview is across everything this person may see: the switcher
        // is for looking *into* one, and a list that shrank to the one chosen
        // would leave no way to choose another.
        return $this->context->withoutSelection(function () use ($request) {
            $allowed = $this->context->allowed();

            $companies = Company::query()
                ->withCount('projects')
                ->when($allowed !== null, fn ($q) => $q->whereIn('companies.id', $allowed ?: [-1]))
                ->when(! $request->boolean('all'), fn ($q) => $q->where('is_active', true))
                ->orderBy('name')
                ->get();

            return ApiResponse::success([
                'companies' => CompanyResource::collection($companies->load('members:id,uuid,name')),
                'metrics' => $this->metrics($request->user(), $companies),
                // Whether the person is held to some of them, so the screen can
                // say so rather than leave them wondering where the rest went.
                'restricted' => $allowed !== null,
            ]);
        });
    }

    public function store(Request $request)
    {
        $this->authorize('create', Company::class);

        $company = Company::create($this->validated($request) + ['is_active' => true]);

        return ApiResponse::created(['company' => new CompanyResource($company)]);
    }

    public function update(Request $request, string $uuid)
    {
        $company = Company::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $company);

        $company->update($this->validated($request, partial: true));

        return ApiResponse::success(['company' => new CompanyResource($company->refresh())]);
    }

    public function destroy(string $uuid)
    {
        $company = Company::where('uuid', $uuid)->firstOrFail();
        $this->authorize('delete', $company);

        // Refused rather than cascaded: a company's projects hold leads, and
        // "remove the company" must never quietly mean "orphan those".
        if (Project::withoutGlobalScopes()->where('company_id', $company->id)->exists()) {
            return ApiResponse::error('Move or remove this company\'s projects first.', null, 422);
        }

        $company->delete();

        return ApiResponse::success(null, 'Company deleted.');
    }

    /** Who works in this company. An empty list takes everybody out — they see everything again. */
    public function members(Request $request, string $uuid)
    {
        $company = Company::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $company);

        $data = $request->validate([
            'user_ids' => ['present', 'array', 'max:200'],
            'user_ids.*' => ['uuid'],
        ]);

        // Resolved against this workspace: naming somebody else's user selects
        // nobody, rather than reaching into their workspace.
        $company->members()->sync(
            User::query()->where('tenant_id', $company->tenant_id)
                ->where('is_active', true)->whereIn('uuid', $data['user_ids'])->pluck('id'),
        );

        return ApiResponse::success(['company' => new CompanyResource($company->load('members:id,uuid,name'))]);
    }

    /**
     * Drill one level: this company's projects, each with its own numbers.
     * The next step down is the project workspace, which already exists.
     */
    public function projects(Request $request, string $uuid)
    {
        $company = Company::where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $company);

        $allowed = $this->context->allowed();

        if ($allowed !== null && ! in_array($company->id, $allowed, true)) {
            abort(404);
        }

        $projects = $this->context->withoutSelection(fn () => Project::query()
            ->where('company_id', $company->id)->orderBy('name')->get(['id', 'uuid', 'name', 'status', 'location']));

        $rows = Lead::query()->visibleTo($request->user())
            ->whereIn('project_id', $projects->pluck('id'))
            ->select('project_id', ...$this->aggregate())
            ->groupBy('project_id')->get()->keyBy('project_id');

        return ApiResponse::success([
            'company' => new CompanyResource($company),
            'projects' => $projects->map(fn (Project $p) => ['id' => $p->uuid, 'name' => $p->name,
                'status' => $p->status, 'location' => $p->location] + $this->shape($rows[$p->id] ?? null))->values(),
        ]);
    }

    /** @return array<int,mixed> */
    private function aggregate(): array
    {
        $open = [LeadStatus::Won->value, LeadStatus::Lost->value];

        return [
            DB::raw('count(*) as leads'),
            DB::raw("count(*) filter (where status not in ('{$open[0]}','{$open[1]}')) as open"),
            DB::raw("count(*) filter (where temperature = 'hot' and status not in ('{$open[0]}','{$open[1]}')) as hot"),
            DB::raw("count(*) filter (where status = '{$open[0]}') as won"),
            DB::raw("count(*) filter (where created_at >= now() - interval '30 days') as new_30d"),
        ];
    }

    private function shape($row): array
    {
        $leads = (int) ($row->leads ?? 0);
        $won = (int) ($row->won ?? 0);

        return [
            'leads' => $leads,
            'open' => (int) ($row->open ?? 0),
            'hot' => (int) ($row->hot ?? 0),
            'won' => $won,
            'new_30d' => (int) ($row->new_30d ?? 0),
            'conversion_rate' => $leads > 0 ? round($won / $leads * 100, 1) : 0.0,
        ];
    }

    /** One grouped query over every company's projects. */
    private function metrics(User $user, $companies): array
    {
        $projectToCompany = Project::withoutGlobalScopes()
            ->whereIn('company_id', $companies->pluck('id'))
            ->pluck('company_id', 'id');

        $byProject = Lead::query()->withoutGlobalScope(CompanyScope::class)
            ->visibleTo($user)
            ->whereIn('project_id', $projectToCompany->keys())
            ->select('project_id', ...$this->aggregate())
            ->groupBy('project_id')->get();

        $totals = [];

        foreach ($byProject as $row) {
            $company = $projectToCompany[$row->project_id];
            foreach (['leads', 'open', 'hot', 'won', 'new_30d'] as $key) {
                $totals[$company][$key] = ($totals[$company][$key] ?? 0) + (int) $row->{$key};
            }
        }

        return $companies->map(fn (Company $c) => ['id' => $c->uuid] + $this->shape((object) ($totals[$c->id] ?? [])))->values()->all();
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
