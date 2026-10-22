<?php

namespace App\Domain\Automation;

use App\Models\Lead;
use App\Models\Project;

/**
 * What a project says about how its own leads are looked after.
 *
 * Stored on the project (`automation_settings`) and absent by default, so a
 * project that has never been set up behaves exactly as the workspace does.
 *
 *   active         automation may contact this project's leads (default yes)
 *   cadence        how fast the journey's waits run: gentle ×2, standard ×1, intensive ×½
 *   sla_minutes    this project's own first-contact target
 *   quiet_hours    {enabled, start, end} — overrides the workspace's
 */
class ProjectSettings
{
    public const CADENCE = ['gentle' => 2.0, 'standard' => 1.0, 'intensive' => 0.5];

    /** @return array<string,mixed> */
    public static function of(?Project $project): array
    {
        return (array) ($project?->automation_settings ?? []);
    }

    public static function forLead(Lead $lead): array
    {
        if (! $lead->project_id) {
            return [];
        }

        $project = $lead->relationLoaded('project') && array_key_exists('automation_settings', $lead->project?->getAttributes() ?? [])
            ? $lead->project
            : Project::withoutGlobalScopes()->find($lead->project_id, ['id', 'automation_settings']);

        return self::of($project);
    }

    public static function isActive(Lead $lead): bool
    {
        return (bool) (self::forLead($lead)['active'] ?? true);
    }

    public static function cadenceFactor(Lead $lead): float
    {
        return self::CADENCE[self::forLead($lead)['cadence'] ?? 'standard'] ?? 1.0;
    }

    /** The project's first-contact target, else the one handed in (the workspace's). */
    public static function firstContactTarget(Lead $lead, int $fallback): int
    {
        $own = (int) (self::forLead($lead)['sla_minutes'] ?? 0);

        return $own > 0 ? $own : $fallback;
    }
}
