<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityType;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Leads\ActivityLogger;
use App\Support\ApiResponse;
use App\Support\ProjectContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tasks: work with a due time that is not a call back.
 *
 * Who sees what follows leads: someone who sees every lead sees every task (within the projects they are limited to);
 * a rep sees the tasks given to them or written by them. A task on a lead or project the person cannot open is a 404,
 * the same answer as a task that does not exist.
 */
class TaskController extends Controller
{
    public function __construct(private ProjectContext $projects, private ActivityLogger $activities) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'bucket' => ['nullable', Rule::in(['overdue', 'today', 'upcoming', 'open', 'done'])],
            'mine' => ['nullable', 'boolean'],
            'lead' => ['nullable', 'uuid'],
        ]);

        $query = $this->visible($request->user())->with(['lead:id,uuid,full_name', 'project:id,uuid,name', 'assignee:id,uuid,name']);

        if ($request->boolean('mine')) {
            $query->where('assigned_to', $request->user()->id);
        }

        if (! empty($data['lead'])) {
            $query->whereHas('lead', fn ($q) => $q->where('uuid', $data['lead']));
        }

        $end = now()->endOfDay();

        match ($data['bucket'] ?? 'open') {
            'overdue' => $query->where('status', 'open')->where('due_at', '<', now()),
            'today' => $query->where('status', 'open')->whereBetween('due_at', [now(), $end]),
            'upcoming' => $query->where('status', 'open')->where(fn ($q) => $q->where('due_at', '>', $end)->orWhereNull('due_at')),
            'done' => $query->where('status', 'done'),
            default => $query->where('status', 'open'),
        };

        $tasks = $query->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->orderByRaw('due_at is null')->orderBy('due_at')->limit(200)->get();

        return ApiResponse::success($tasks->map(fn (Task $t) => $this->present($t))->values());
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $this->validated($request);

        $task = Task::create($data + ['created_by' => $user->id, 'assigned_to' => $data['assigned_to'] ?? $user->id, 'status' => 'open']);

        if ($task->lead) {
            $this->activities->log($task->lead, ActivityType::Updated, $user, 'مهمة: '.$task->title, ['task' => $task->uuid]);
        }

        return ApiResponse::created($this->present($task->load(['lead:id,uuid,full_name', 'project:id,uuid,name', 'assignee:id,uuid,name'])));
    }

    public function update(Request $request, string $uuid)
    {
        $task = $this->visible($request->user())->where('uuid', $uuid)->firstOrFail();
        $data = $this->validated($request, partial: true);

        if (isset($data['status'])) {
            $data['completed_at'] = $data['status'] === 'done' ? ($task->completed_at ?? now()) : null;
        }

        $task->update($data);

        return ApiResponse::success($this->present($task->fresh(['lead:id,uuid,full_name', 'project:id,uuid,name', 'assignee:id,uuid,name'])));
    }

    public function destroy(Request $request, string $uuid)
    {
        $task = $this->visible($request->user())->where('uuid', $uuid)->firstOrFail();
        abort_unless($task->created_by === $request->user()->id || $request->user()->seesAllLeads(), 403, 'Your role does not include this.');

        $task->delete();

        return ApiResponse::noContent();
    }

    private function visible(User $user): Builder
    {
        $query = Task::query();

        if (! $user->seesAllLeads()) {
            $query->where(fn ($q) => $q->where('assigned_to', $user->id)->orWhere('created_by', $user->id));
        }

        // Limited to some projects: a task about a lead or project outside them is not there.
        if (($ids = $this->projects->ids()) !== null) {
            $ids = $ids ?: [-1];
            $query->where(function ($q) use ($ids, $user) {
                $q->whereIn('project_id', $ids)
                    ->orWhereIn('lead_id', fn ($l) => $l->select('id')->from('leads')->whereIn('project_id', $ids))
                    ->orWhere(fn ($own) => $own->whereNull('project_id')->whereNull('lead_id')->where('assigned_to', $user->id));
            });
        }

        return $query;
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $sometimes = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'title' => [$sometimes, 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'due_at' => ['nullable', 'date'],
            'priority' => ['sometimes', Rule::in(Task::PRIORITIES)],
            'status' => ['sometimes', Rule::in(Task::STATUSES)],
            'lead' => ['nullable', 'uuid'],
            'project' => ['nullable', 'uuid'],
            'assignee' => ['nullable', 'uuid'],
        ]);

        $out = array_intersect_key($data, array_flip(['title', 'notes', 'due_at', 'priority', 'status']));

        // Every reference is looked up through the scopes the person works under: something they cannot open is refused.
        if (array_key_exists('lead', $data)) {
            $lead = $data['lead'] ? Lead::query()->where('uuid', $data['lead'])->first() : null;
            abort_if($data['lead'] && $lead === null, 422, 'Unknown lead.');
            $out['lead_id'] = $lead?->id;
            $out['project_id'] ??= $lead?->project_id;
        }

        if (array_key_exists('project', $data)) {
            $project = $data['project'] ? Project::query()->where('uuid', $data['project'])->first() : null;
            abort_if($data['project'] && $project === null, 422, 'Unknown project.');
            $out['project_id'] = $project?->id;
        }

        if (array_key_exists('assignee', $data)) {
            $user = $request->user();
            $assignee = $data['assignee'] ? User::query()->where('tenant_id', $user->tenant_id)->where('uuid', $data['assignee'])->where('is_active', true)->first() : null;
            abort_if($data['assignee'] && $assignee === null, 422, 'Unknown person.');
            // A rep keeps their own tasks; giving work to someone else is a manager's call.
            abort_if($assignee && $assignee->id !== $user->id && ! $user->seesAllLeads(), 403, 'Your role does not include this.');
            $out['assigned_to'] = $assignee?->id;
        }

        return $out;
    }

    private function present(Task $t): array
    {
        return [
            'id' => $t->uuid,
            'title' => $t->title,
            'notes' => $t->notes,
            'due_at' => $t->due_at?->toIso8601String(),
            'priority' => $t->priority,
            'status' => $t->status,
            'overdue' => $t->status === 'open' && $t->due_at !== null && $t->due_at->isPast(),
            'completed_at' => $t->completed_at?->toIso8601String(),
            'lead' => $t->lead ? ['id' => $t->lead->uuid, 'name' => $t->lead->full_name] : null,
            'project' => $t->project ? ['id' => $t->project->uuid, 'name' => $t->project->name] : null,
            'assignee' => $t->assignee ? ['id' => $t->assignee->uuid, 'name' => $t->assignee->name] : null,
        ];
    }
}
