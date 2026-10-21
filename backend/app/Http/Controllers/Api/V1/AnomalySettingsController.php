<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Insights\AnomalySettings;
use App\Http\Controllers\Controller;
use App\Models\AutomationRule;
use App\Models\Project;
use App\Models\TenantSetting;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * How sensitive the alerts are: the workspace's lines, and a project's own where it needs different.
 * Reading is for those who read automation; changing is for those who make rules.
 */
class AnomalySettingsController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function show(Request $request)
    {
        $this->authorize('viewAny', AutomationRule::class);

        return ApiResponse::success($this->present($this->project($request)));
    }

    public function update(Request $request)
    {
        $this->authorize('create', AutomationRule::class);

        $data = $request->validate([
            'project_id' => ['nullable', 'uuid'],
            'alerts' => ['required', 'array', 'max:20'],
            'alerts.*' => ['array'],
            'alerts.*.enabled' => ['sometimes', 'nullable', 'boolean'],
            'alerts.*.value' => ['sometimes', 'nullable', 'integer'],
        ]);

        $project = $this->project($request);
        $target = $project ?? TenantSetting::query()->firstOrFail();
        $before = (array) ($target->anomaly_settings ?? []);
        $after = AnomalySettings::merge($before, $data['alerts']);

        $target->forceFill(['anomaly_settings' => $after === [] ? null : $after])->save();
        $this->audit->log('anomaly_settings.changed', $request->user(), $target, ['project' => $project?->name, 'before' => $before, 'after' => $after]);

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
        return ['alerts' => AnomalySettings::for(TenantSetting::query()->first(), $project)->describe(), 'project' => $project ? ['id' => $project->uuid, 'name' => $project->name] : null];
    }
}
