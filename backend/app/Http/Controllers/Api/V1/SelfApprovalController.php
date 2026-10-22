<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Campaigns\SelfApproval;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\TenantSetting;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Safe self-approve: whether a person's own pause, resume or guarded budget change runs at once.
 * The workspace's setting, or one project's own. Only an admin changes it — it decides what skips
 * a second look — and every change is on the audit trail.
 */
class SelfApprovalController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function show(Request $request)
    {
        $this->authorize('view', $request->user()->tenant);

        return ApiResponse::success($this->present($this->project($request)));
    }

    public function update(Request $request)
    {
        $this->authorize('update', $request->user()->tenant);

        $data = $request->validate([
            'project_id' => ['nullable', 'uuid'],
            'enabled' => ['sometimes', 'nullable', 'boolean'],
            'actions' => ['sometimes', 'nullable', 'array', 'max:10'],
            'actions.*' => ['string', Rule::in(SelfApproval::CONFIGURABLE)],
            'max_budget_change_pct' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
        ]);

        $project = $this->project($request);
        $target = $project ?? TenantSetting::query()->firstOrFail();
        $before = (array) ($target->self_approval ?? []);
        $after = SelfApproval::merge($before, array_diff_key($data, ['project_id' => 1]));

        $target->forceFill(['self_approval' => $after === [] ? null : $after])->save();
        $this->audit->log('self_approval.changed', $request->user(), $target, ['project' => $project?->name, 'before' => $before, 'after' => $after]);

        return ApiResponse::success($this->present($project));
    }

    private function project(Request $request): ?Project
    {
        $uuid = $request->input('project_id', $request->query('project_id'));

        return is_string($uuid) && $uuid !== '' ? Project::where('uuid', $uuid)->firstOrFail() : null;
    }

    /** @return array<string,mixed> */
    private function present(?Project $project): array
    {
        return SelfApproval::for(TenantSetting::query()->first(), $project)->describe() + ['project' => $project ? ['id' => $project->uuid, 'name' => $project->name] : null];
    }
}
