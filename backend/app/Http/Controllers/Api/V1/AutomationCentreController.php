<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Agents\AgentDirectory;
use App\Domain\Automation\ActionWords;
use App\Domain\Automation\AutomationCentre;
use App\Domain\Automation\AutomationPack;
use App\Http\Controllers\Controller;
use App\Models\AutomationActionLog;
use App\Models\AutomationRule;
use App\Models\Project;
use App\Models\Routine;
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

        $page = $this->centre->history($filters);

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
        ]);

        $pack = app(AutomationPack::class)->complete($project, $data);
        $this->audit->log('project.automation_pack', $request->user(), null, ['project' => $project->name]);

        return ApiResponse::success(['pack' => $pack]);
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
