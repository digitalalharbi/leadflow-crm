<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LeadStatus;
use App\Enums\QualificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Project::class);

        $projects = Project::query()
            ->withCount('leads')
            // Eager, because the list is where a manager sees who works what.
            ->with('team:id,uuid,name')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'ilike', '%'.$request->input('search').'%'))
            ->orderBy('name')
            ->get();

        /*
         * A project is a business unit, not a record: the question a developer
         * has is which of their projects is actually selling. One grouped query
         * for all of them rather than a count per card — the page was going to
         * be a list of names otherwise.
         */
        /*
         * "Reached", not "sitting in" — the same definition the dashboard funnel
         * uses. Counting only leads currently in the qualified stage made a card
         * read "0 qualified · 5 appointments · 5 won", which is not a thing that
         * can happen, and drew a conversion bar with the middle segment missing.
         */
        $metrics = Lead::query()
            ->whereNotNull('project_id')
            ->visibleTo($request->user())
            ->selectRaw(
                'project_id,
                 count(*) as leads,
                 count(*) filter (where qualification_status = ? or status in (?, ?, ?)) as qualified,
                 count(*) filter (where status in (?, ?)) as appointments,
                 count(*) filter (where status = ?) as won',
                [
                    QualificationStatus::Qualified->value,
                    LeadStatus::Qualified->value, LeadStatus::Appointment->value, LeadStatus::Won->value,
                    LeadStatus::Appointment->value, LeadStatus::Won->value,
                    LeadStatus::Won->value,
                ],
            )
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');

        return ApiResponse::success([
            'projects' => ProjectResource::collection($projects),
            'metrics' => $projects->map(function (Project $project) use ($metrics) {
                $row = $metrics[$project->id] ?? null;
                $leads = (int) ($row->leads ?? 0);
                $qualified = (int) ($row->qualified ?? 0);

                return [
                    'id' => $project->uuid,
                    'leads' => $leads,
                    'qualified' => $qualified,
                    'appointments' => (int) ($row->appointments ?? 0),
                    'won' => (int) ($row->won ?? 0),
                    'qualification_rate' => $leads > 0 ? round($qualified / $leads * 100, 1) : 0.0,
                ];
            })->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Project::class);

        $data = $this->validatePayload($request);

        // Not a column: a project can be created with a team, synced after it
        // exists and has an id to sync against.
        $team = $data['team'] ?? null;
        unset($data['team']);

        $project = Project::create($data);

        if ($team !== null) {
            $project->team()->sync(
                User::query()
                    ->where('tenant_id', $project->tenant_id)
                    ->whereIn('uuid', $team)
                    ->pluck('id')
            );
        }

        return ApiResponse::created(['project' => new ProjectResource($project->load('team'))]);
    }

    public function show(string $uuid)
    {
        $project = Project::withCount('leads')->where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $project);

        return ApiResponse::success(['project' => new ProjectResource($project)]);
    }

    public function update(Request $request, string $uuid)
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $project);

        $data = $this->validatePayload($request, partial: true);

        // Not a column: the team is a relation, synced below.
        unset($data['team']);

        $project->update($data);

        /*
         * The team, if the request named one. Scoped to this workspace when it
         * is resolved, because the pivot has no tenant of its own — the query
         * is the only thing keeping workspaces apart here.
         */
        if ($request->has('team')) {
            $ids = User::query()
                ->where('tenant_id', $project->tenant_id)
                ->whereIn('uuid', $request->input('team') ?: [])
                ->pluck('id');

            $project->team()->sync($ids);
        }

        return ApiResponse::success(['project' => new ProjectResource($project->fresh()->load('team'))]);
    }

    public function destroy(string $uuid)
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $this->authorize('delete', $project);

        $project->delete(); // soft delete; existing leads keep their reference

        return ApiResponse::success(null, 'Project deleted.');
    }

    private function validatePayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'developer_name' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'starting_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'status' => ['nullable', 'in:active,paused,sold_out'],
            'notes' => ['nullable', 'string', 'max:2000'],

            /*
             * The reps who work this project, by uuid. Validated as a shape
             * only — which users those uuids are is resolved against this
             * workspace when they are synced, so naming somebody else's rep
             * selects nobody rather than reaching into their workspace.
             */
            'team' => ['sometimes', 'nullable', 'array', 'max:50'],
            'team.*' => ['uuid'],
        ]);
    }
}
