<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Project::class);

        $projects = Project::query()
            ->withCount('leads')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'ilike', '%'.$request->input('search').'%'))
            ->orderBy('name')
            ->get();

        return ApiResponse::success(['projects' => ProjectResource::collection($projects)]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Project::class);

        $data = $this->validatePayload($request);
        $project = Project::create($data);

        return ApiResponse::created(['project' => new ProjectResource($project)]);
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

        $project->update($this->validatePayload($request, partial: true));

        return ApiResponse::success(['project' => new ProjectResource($project->fresh())]);
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
        ]);
    }
}
