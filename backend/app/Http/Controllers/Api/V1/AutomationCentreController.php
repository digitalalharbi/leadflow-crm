<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Agents\AgentDirectory;
use App\Domain\Automation\ActionWords;
use App\Domain\Automation\AutomationCentre;
use App\Domain\Automation\AutomationPack;
use App\Domain\Automation\ProjectSettings;
use App\Domain\Automation\Thresholds;
use App\Http\Controllers\Controller;
use App\Models\AutomationActionLog;
use App\Models\AutomationRule;
use App\Models\Project;
use App\Models\Routine;
use App\Models\TenantSetting;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use App\Support\RoutineClock;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The automation centre's numbers, its Action History, and the scoring each
 * project runs on. Rules themselves stay in AutomationRuleController.
 */
class AutomationCentreController extends Controller
{
    /** What a project may tune, and the workspace default for each. */
    private const SCORING = [
        'whatsapp_reply' => ['scoring.weights.replied_on_whatsapp', -50, 50],
        'email_open' => ['scoring.engagement.email_opened', -50, 50],
        'email_click' => ['scoring.engagement.link_clicked', -50, 50],
        'brochure_open' => ['scoring.engagement.file_opened', -50, 50],
        'qualification_completed' => ['scoring.extra.qualification_completed', -50, 50],
        'appointment_request' => ['scoring.weights.wants_appointment', -50, 60],
        'no_reply_24h' => ['scoring.extra.no_reply_24h', -50, 50],
        'engagement_max' => ['scoring.engagement.max', 0, 100],
        'warm_from' => ['scoring.temperature.warm_from', 1, 99],
        'hot_from' => ['scoring.temperature.hot_from', 1, 99],
        'priority_from' => ['scoring.temperature.priority_from', 1, 100],
    ];

    public function __construct(
        private readonly AutomationCentre $centre,
        private readonly AuditLogger $audit,
    ) {}

    public function summary(Request $request)
    {
        $this->authorize('viewAny', AutomationRule::class);

        return ApiResponse::success($this->centre->summary(in_array((int) $request->integer('days', 7), [1, 7, 30], true) ? (int) $request->integer('days', 7) : 7));
    }

    public function history(Request $request)
    {
        $this->authorize('viewAny', AutomationRule::class);

        $filters = $request->validate([
            'result' => ['nullable', Rule::in(AutomationActionLog::RESULTS)],
            'action' => ['nullable', 'string', 'max:40'],
            'rule' => ['nullable', 'uuid'],
            'lead' => ['nullable', 'uuid'],
        ]);

        $page = $this->centre->history($filters, viewer: $request->user());

        return ApiResponse::success([
            'entries' => collect($page->items())->map(fn (AutomationActionLog $log) => [
                'id' => $log->uuid,
                'at' => $log->happened_at?->toIso8601String(),
                'lead' => $log->lead ? ['id' => $log->lead->uuid, 'name' => $log->lead->full_name, 'score' => (int) $log->lead->score] : null,
                'rule' => $log->rule_name,
                'rule_id' => $log->rule?->uuid,
                'action' => $log->action_type,
                'action_words' => ActionWords::for(['type' => $log->action_type]),
                'channel' => $log->channel,
                'mode' => $log->mode,
                'before' => $log->before,
                'after' => $log->after,
                'result' => $log->result,
                'description' => $log->description,
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * Everything that runs on its own: the system's sweeps, each with how
     * often and when it last finished, and the agents' routines.
     */
    public function routines()
    {
        $this->authorize('viewAny', AutomationRule::class);

        $system = collect(RoutineClock::SYSTEM)->map(fn ($spec, $key) => [
            'key' => $key,
            'kind' => 'system',
            'cadence' => $spec[1],
            'last_run_at' => RoutineClock::last($key),
            'status' => RoutineClock::last($key) ? 'ok' : 'not_run',
            'agent' => null,
        ])->values();

        app(AgentDirectory::class)->ensure();

        $agents = Routine::query()->orderBy('id')->get()->map(fn ($routine) => [
            'key' => $routine->key,
            'kind' => 'agent',
            'cadence' => $routine->cron,
            'last_run_at' => $routine->last_run_at?->toIso8601String(),
            'status' => $routine->last_status ?? 'not_run',
            'agent' => $routine->agent,
            'is_active' => (bool) $routine->is_active,
        ]);

        return ApiResponse::success(['routines' => $system->concat($agents)->values()->all()]);
    }

    public function pack(string $uuid)
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $this->authorize('viewAny', AutomationRule::class);

        return ApiResponse::success(['pack' => app(AutomationPack::class)->status($project)]);
    }

    public function completePack(Request $request, string $uuid)
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $this->authorize('create', AutomationRule::class);

        $data = $request->validate([
            'service' => ['sometimes', 'nullable', 'string', 'max:160'],
            'language' => ['sometimes', 'nullable', Rule::in(['ar', 'en'])],
            'cadence' => ['sometimes', 'nullable', Rule::in(array_keys(ProjectSettings::CADENCE))],
            'sla_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10080'],
            'quiet_hours' => ['sometimes', 'nullable', 'array'],
            'quiet_hours.enabled' => ['required_with:quiet_hours', 'boolean'],
            'quiet_hours.start' => ['sometimes', 'date_format:H:i'],
            'quiet_hours.end' => ['sometimes', 'date_format:H:i'],
        ]);

        $pack = app(AutomationPack::class)->complete($project, $data);
        $this->audit->log('project.automation_pack', $request->user(), null, ['project' => $project->name]);

        return ApiResponse::success(['pack' => $pack]);
    }

    /** One press: complete what defaults can complete, switch the journey on, let this project's leads be contacted. */
    public function activatePack(Request $request, string $uuid)
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $this->authorize('create', AutomationRule::class);

        $pack = app(AutomationPack::class)->activate($project, $request->validate([
            'service' => ['sometimes', 'nullable', 'string', 'max:160'],
            'language' => ['sometimes', 'nullable', Rule::in(['ar', 'en'])],
        ]));
        $this->audit->log('project.automation_activated', $request->user(), null, ['project' => $project->name]);

        return ApiResponse::success(['pack' => $pack]);
    }

    public function pausePack(Request $request, string $uuid)
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $this->authorize('create', AutomationRule::class);

        $pack = app(AutomationPack::class)->pause($project);
        $this->audit->log('project.automation_paused', $request->user(), null, ['project' => $project->name]);

        return ApiResponse::success(['pack' => $pack]);
    }

    /** Every threshold in force for a project, and where each one comes from. */
    public function thresholds(string $uuid)
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $this->authorize('viewAny', AutomationRule::class);

        return ApiResponse::success(['thresholds' => Thresholds::describe(TenantSetting::query()->first(), $project)]);
    }

    /**
     * Set or clear the lines a project holds its own leads to. A null puts the
     * workspace's value back. Scoring lines live with the scoring; the rest with
     * the automation settings — the caller does not need to know.
     */
    public function updateThresholds(Request $request, string $uuid)
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $this->authorize('create', AutomationRule::class);

        $rules = [];
        foreach (Thresholds::LIMITS as $key => [$min, $max]) {
            $rules[$key] = ['sometimes', 'nullable', 'integer', "min:{$min}", "max:{$max}"];
        }
        $data = $request->validate($rules);

        $scoring = (array) ($project->scoring ?? []);
        $settings = ProjectSettings::of($project);

        foreach ($data as $key => $value) {
            $bucket = in_array($key, ['hot_from', 'warm_from', 'priority_from'], true) ? 'scoring' : 'settings';

            if ($value === null) {
                unset($$bucket[$key]);
            } else {
                $$bucket[$key] = (int) $value;
            }
        }

        if (isset($data['hot_from'], $data['warm_from']) && $data['warm_from'] >= $data['hot_from']) {
            return ApiResponse::error('Hot must start above warm.', ['hot_from' => ['Hot must start above warm.']], 422);
        }

        $project->forceFill(['scoring' => $scoring ?: null, 'automation_settings' => $settings ?: null])->save();
        $this->audit->log('project.thresholds_changed', $request->user(), null, ['project' => $project->name, 'keys' => array_keys($data)]);

        return ApiResponse::success(['thresholds' => Thresholds::describe(TenantSetting::query()->first(), $project->refresh())]);
    }

    public function scoring(string $uuid)
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $this->authorize('viewAny', AutomationRule::class);

        return ApiResponse::success(['scoring' => $this->effective($project)]);
    }

    public function updateScoring(Request $request, string $uuid)
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $this->authorize('create', AutomationRule::class);

        $rules = [];

        foreach (self::SCORING as $key => [, $min, $max]) {
            $rules[$key] = ['sometimes', 'nullable', 'integer', "min:{$min}", "max:{$max}"];
        }

        $data = $request->validate($rules);
        $current = (array) ($project->scoring ?? []);

        foreach ($data as $key => $value) {
            if ($value === null) {
                unset($current[$key]);
            } else {
                $current[$key] = (int) $value;
            }
        }

        $project->forceFill(['scoring' => $current ?: null])->save();
        $this->audit->log('project.scoring_changed', $request->user(), null, ['project' => $project->name, 'keys' => array_keys($data)]);

        return ApiResponse::success(['scoring' => $this->effective($project->refresh())]);
    }

    /** Each tunable value: what the project set, else the workspace default. */
    private function effective(Project $project): array
    {
        $own = (array) ($project->scoring ?? []);

        return collect(self::SCORING)->map(fn ($spec, $key) => [
            'value' => (int) ($own[$key] ?? config($spec[0])),
            'default' => (int) config($spec[0]),
            'custom' => array_key_exists($key, $own),
        ])->all();
    }
}
