<?php

namespace App\Domain\Automation;

use App\Models\Lead;
use App\Models\Project;
use App\Models\TenantSetting;

/**
 * Every line the product draws, in one place, set at the workspace and
 * overridable per project.
 *
 *   hot_from, warm_from, priority_from   what score makes a lead hot / warm / a priority
 *   high_value_from                      what budget makes a lead "high value"
 *   sla_minutes                          how long a new lead may wait for a first contact
 *   stalled_after_days                   how long untouched makes an open lead stalled
 *   max_contacts_per_day                 the cap on automated messages to one lead
 *   cooldown_minutes                     the gap between two automated messages
 *
 * A project that says nothing gets the workspace's; a workspace that says
 * nothing gets the product's default. `describe()` reports, for each, the
 * value in force and where it came from, so a screen can say "from the
 * workspace" instead of leaving somebody to wonder why a number is what it is.
 */
class Thresholds
{
    public const KEYS = ['hot_from', 'warm_from', 'priority_from', 'high_value_from', 'sla_minutes', 'stalled_after_days', 'max_contacts_per_day', 'cooldown_minutes', 'sla_hot_minutes', 'sla_interested_minutes', 'sla_visit_minutes', 'sla_reservation_minutes'];

    /** Bounds a person may set, so a typo cannot make every lead "stalled". */
    public const LIMITS = [
        'hot_from' => [1, 99], 'warm_from' => [1, 99], 'priority_from' => [1, 100],
        'high_value_from' => [1, 1_000_000_000], 'sla_minutes' => [1, 10080], 'stalled_after_days' => [1, 90],
        'max_contacts_per_day' => [1, 50], 'cooldown_minutes' => [0, 1440],
        'sla_hot_minutes' => [1, 10080], 'sla_interested_minutes' => [1, 10080], 'sla_visit_minutes' => [1, 10080], 'sla_reservation_minutes' => [1, 10080],
    ];

    /** Where a project keeps each of its overrides. */
    private const PROJECT_SCORING = ['hot_from', 'warm_from', 'priority_from'];

    public static function defaults(): array
    {
        return [
            'hot_from' => (int) config('scoring.temperature.hot_from'),
            'warm_from' => (int) config('scoring.temperature.warm_from'),
            'priority_from' => (int) config('scoring.temperature.priority_from'),
            'high_value_from' => 500_000,
            'sla_minutes' => 30,
            'stalled_after_days' => 3,
            'max_contacts_per_day' => 6,
            'cooldown_minutes' => 0,
            // How long an interested lead may wait for a person, by how far along they are.
            'sla_hot_minutes' => 15,
            'sla_interested_minutes' => 60,
            'sla_visit_minutes' => 30,
            'sla_reservation_minutes' => 5,
        ];
    }

    /** What the workspace has set (null where it has not), straight from its settings. */
    public static function workspaceOwn(?TenantSetting $settings): array
    {
        return [
            'hot_from' => $settings?->score_hot_threshold,
            'warm_from' => $settings?->score_warm_threshold,
            'priority_from' => $settings?->score_priority_threshold,
            'high_value_from' => $settings?->high_value_from,
            'sla_minutes' => $settings?->first_contact_target_minutes,
            'stalled_after_days' => $settings?->stalled_after_days,
            'max_contacts_per_day' => $settings?->max_contacts_per_day,
            'cooldown_minutes' => $settings?->contact_cooldown_minutes,
            'sla_hot_minutes' => $settings?->sla_hot_minutes,
            'sla_interested_minutes' => $settings?->sla_interested_minutes,
            'sla_visit_minutes' => $settings?->sla_visit_minutes,
            'sla_reservation_minutes' => $settings?->sla_reservation_minutes,
        ];
    }

    /** What a project has overridden: scoring lines live with the scoring, the rest with the automation settings. */
    public static function projectOwn(?Project $project): array
    {
        $scoring = (array) ($project?->scoring ?? []);
        $own = ProjectSettings::of($project);
        $out = [];

        foreach (self::PROJECT_SCORING as $key) {
            if (isset($scoring[$key])) {
                $out[$key] = (int) $scoring[$key];
            }
        }

        foreach (['high_value_from', 'sla_minutes', 'stalled_after_days', 'max_contacts_per_day', 'cooldown_minutes', 'sla_hot_minutes', 'sla_interested_minutes', 'sla_visit_minutes', 'sla_reservation_minutes'] as $key) {
            if (isset($own[$key]) && $own[$key] !== '') {
                $out[$key] = (int) $own[$key];
            }
        }

        return $out;
    }

    /**
     * The value in force for each threshold: project, else workspace, else default.
     *
     * @return array<string,array{value:int, source:string, workspace:int, default:int}>
     */
    public static function describe(?TenantSetting $settings, ?Project $project = null): array
    {
        $defaults = self::defaults();
        $workspace = self::workspaceOwn($settings);
        $mine = self::projectOwn($project);
        $out = [];

        foreach (self::KEYS as $key) {
            $ws = $workspace[$key] !== null ? (int) $workspace[$key] : $defaults[$key];
            $out[$key] = [
                'value' => $mine[$key] ?? $ws,
                'source' => array_key_exists($key, $mine) ? 'project' : ($workspace[$key] !== null && (int) $workspace[$key] !== $defaults[$key] ? 'workspace' : 'default'),
                'workspace' => $ws,
                'default' => $defaults[$key],
            ];
        }

        return $out;
    }

    /** @var array<int,?TenantSetting> */
    private static array $settingsCache = [];

    /** @var array<int,?Project> */
    private static array $projectCache = [];

    /**
     * Forgets what was read. Called when a setting or a project changes and
     * between queued jobs, so a long-lived worker never judges by old lines.
     */
    public static function flush(): void
    {
        self::$settingsCache = [];
        self::$projectCache = [];
    }

    /**
     * One value, for one lead, honouring its project.
     *
     * Read once per workspace and once per project and then remembered: a list
     * of fifty leads asks this fifty times and must not cost a hundred queries.
     */
    public static function forLead(Lead $lead, string $key): int
    {
        $settings = self::settingsFor((int) $lead->tenant_id);
        $project = null;

        if ($lead->project_id) {
            $project = array_key_exists($lead->project_id, self::$projectCache)
                ? self::$projectCache[$lead->project_id]
                : (self::$projectCache[$lead->project_id] = Project::withoutGlobalScopes()->find($lead->project_id));
        }

        return self::describe($settings, $project)[$key]['value'];
    }

    /** A workspace's settings row, read once and remembered with the rest of the lines. */
    public static function settingsFor(int $tenantId): ?TenantSetting
    {
        return self::$settingsCache[$tenantId] ??= TenantSetting::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
    }

    /** The workspace's own value for one threshold, for code that has no lead to hand. */
    public static function workspace(string $key): int
    {
        return self::describe(TenantSetting::query()->first())[$key]['value'];
    }

    /**
     * The same rule as a SQL expression, per lead: the project's override when
     * it has one, else the workspace's value baked in. Used by the lists and
     * counts so a chip, its list and a lead's own screen cannot disagree.
     */
    public static function sql(string $key, int $workspaceValue): string
    {
        $path = in_array($key, self::PROJECT_SCORING, true) ? 'scoring' : 'automation_settings';
        $column = $path === 'scoring' ? "p.scoring->>'{$key}'" : "p.automation_settings->>'{$key}'";

        return "coalesce((select nullif({$column}, '')::int from projects p where p.id = leads.project_id), {$workspaceValue})";
    }
}
