<?php

namespace App\Domain\Sla;

use App\Models\Lead;
use App\Domain\Automation\Thresholds;

/**
 * The first-contact target for one lead, when the workspace has said that some leads cannot wait as long as others.
 *
 * Two kinds of rule: by where the lead came from (`by_source`, keyed by platform) and for priority leads
 * (`priority_minutes`). When several apply the strictest wins — a priority lead from Meta is held to whichever of
 * the two is shorter, and never to a longer target than the project or workspace already set.
 */
class SlaRules
{
    public const MAX_SOURCES = 20;

    /**
     * From the settings row the thresholds already read and remember — never a query of its own, so a lead screen
     * costs the same however its target is worked out.
     *
     * @return array{by_source: array<string,int>, priority_minutes: ?int}
     */
    public static function forTenant(int $tenantId): array
    {
        return self::normalise(Thresholds::settingsFor($tenantId)?->sla_rules);
    }

    /** @return array{by_source: array<string,int>, priority_minutes: ?int} */
    public static function normalise(mixed $raw): array
    {
        $raw = is_string($raw) ? json_decode($raw, true) : $raw;
        $raw = is_array($raw) ? $raw : [];
        $bySource = [];

        foreach ((array) ($raw['by_source'] ?? []) as $source => $minutes) {
            if (is_string($source) && preg_match('/^[a-z_]{2,32}$/', $source) && (int) $minutes >= 1) {
                $bySource[$source] = min(10080, (int) $minutes);
            }
        }

        $priority = (int) ($raw['priority_minutes'] ?? 0);

        return ['by_source' => $bySource, 'priority_minutes' => $priority >= 1 ? min(10080, $priority) : null];
    }

    /** The strictest target that applies to this lead, starting from the project's or workspace's. */
    public static function targetFor(Lead $lead, int $base): int
    {
        $rules = self::forTenant((int) $lead->tenant_id);
        $candidates = [$base];

        if ($lead->platform !== null && isset($rules['by_source'][$lead->platform])) {
            $candidates[] = $rules['by_source'][$lead->platform];
        }

        if ($rules['priority_minutes'] !== null && $lead->is_priority) {
            $candidates[] = $rules['priority_minutes'];
        }

        return max(1, min($candidates));
    }
}
