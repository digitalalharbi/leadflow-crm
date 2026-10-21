<?php

namespace App\Domain\Insights;

use App\Models\Project;
use App\Models\TenantSetting;

/**
 * How sensitive each alert is, and whether it is on — the workspace's choice, with a project able to
 * ask for something different for its own numbers.
 *
 * Eight alerts, seven of which have a line you can move (the lead spike is simply on or off):
 *
 *   lead_drop            leads fell by this much against the usual
 *   cpl_spike            cost per lead rose by this much
 *   qualified_rate_drop  the share who qualify fell by this much
 *   no_response_spike    this share of recent leads got no contact
 *   tracking_drop        tracking events fell by this much (a gap in what is recorded)
 *   backlog_growth       the sales backlog grew by this much
 *   spend_no_qualified   this much was spent in three days without one qualified lead
 *
 * The line in force is the project's if it set one, else the workspace's, else the default below;
 * `defaults()` are exactly what the detector used before it could be configured, so changing nothing
 * changes nothing.
 */
class AnomalySettings
{
    /** @var array<string,array{key:?string,default:?int,min:?int,max:?int,unit:?string}> */
    public const ALERTS = [
        'lead_spike' => ['key' => null, 'default' => null, 'min' => null, 'max' => null, 'unit' => null],
        'lead_drop' => ['key' => 'drop_pct', 'default' => 60, 'min' => 20, 'max' => 95, 'unit' => 'percent'],
        'cpl_spike' => ['key' => 'rise_pct', 'default' => 60, 'min' => 20, 'max' => 500, 'unit' => 'percent'],
        'qualified_rate_drop' => ['key' => 'drop_pct', 'default' => 30, 'min' => 10, 'max' => 90, 'unit' => 'percent'],
        'no_response_spike' => ['key' => 'share_pct', 'default' => 30, 'min' => 10, 'max' => 100, 'unit' => 'percent'],
        'tracking_drop' => ['key' => 'drop_pct', 'default' => 60, 'min' => 30, 'max' => 95, 'unit' => 'percent'],
        'backlog_growth' => ['key' => 'rise_pct', 'default' => 50, 'min' => 20, 'max' => 500, 'unit' => 'percent'],
        'spend_no_qualified' => ['key' => 'min_spend', 'default' => 300, 'min' => 1, 'max' => 100_000_000, 'unit' => 'money'],
    ];

    /** @param  array<string,array{enabled?:bool,value?:int}>  $workspace @param  array<string,array{enabled?:bool,value?:int}>  $project */
    public function __construct(private array $workspace = [], private array $project = []) {}

    /** The workspace's own lines, or a project's laid over them. */
    public static function for(?TenantSetting $settings, ?Project $project = null): self
    {
        return new self((array) ($settings?->anomaly_settings ?? []), (array) ($project?->anomaly_settings ?? []));
    }

    public function enabled(string $kind): bool
    {
        return (bool) ($this->project[$kind]['enabled'] ?? $this->workspace[$kind]['enabled'] ?? true);
    }

    /** The line this alert fires at, in force now. */
    public function value(string $kind): ?int
    {
        $spec = self::ALERTS[$kind];

        return $spec['key'] === null ? null : (int) ($this->project[$kind]['value'] ?? $this->workspace[$kind]['value'] ?? $spec['default']);
    }

    /** Whether the project asked for anything of its own. */
    public function overrides(): bool
    {
        return array_filter($this->project, fn ($s) => is_array($s) && $s !== []) !== [];
    }

    /**
     * Every alert with what is in force and where it came from, for the settings screen.
     *
     * @return list<array<string,mixed>>
     */
    public function describe(): array
    {
        return array_map(function (string $kind, array $spec) {
            $own = $this->project[$kind] ?? [];
            $ws = $this->workspace[$kind] ?? [];

            return [
                'kind' => $kind,
                'enabled' => $this->enabled($kind),
                'enabled_source' => array_key_exists('enabled', $own) ? 'project' : (array_key_exists('enabled', $ws) ? 'workspace' : 'default'),
                'threshold' => $spec['key'] === null ? null : [
                    'value' => $this->value($kind),
                    'source' => isset($own['value']) ? 'project' : (isset($ws['value']) ? 'workspace' : 'default'),
                    'workspace' => (int) ($ws['value'] ?? $spec['default']),
                    'default' => $spec['default'], 'min' => $spec['min'], 'max' => $spec['max'], 'unit' => $spec['unit'],
                ],
            ];
        }, array_keys(self::ALERTS), self::ALERTS);
    }

    /**
     * Cleans what was sent into what is kept: known alerts only, a switch and a line within its
     * range. A null clears a setting (back to what is inherited).
     *
     * @param  array<string,mixed>  $given
     * @param  array<string,array{enabled?:bool,value?:int}>  $current
     * @return array<string,array{enabled?:bool,value?:int}>
     */
    public static function merge(array $current, array $given): array
    {
        foreach ($given as $kind => $change) {
            if (! isset(self::ALERTS[$kind]) || ! is_array($change)) {
                continue;
            }

            $spec = self::ALERTS[$kind];
            $row = $current[$kind] ?? [];

            if (array_key_exists('enabled', $change)) {
                $change['enabled'] === null ? $row = array_diff_key($row, ['enabled' => 1]) : $row['enabled'] = (bool) $change['enabled'];
            }

            if (array_key_exists('value', $change) && $spec['key'] !== null) {
                $change['value'] === null ? $row = array_diff_key($row, ['value' => 1]) : $row['value'] = max($spec['min'], min($spec['max'], (int) $change['value']));
            }

            $row === [] ? $current = array_diff_key($current, [$kind => 1]) : $current[$kind] = $row;
        }

        return $current;
    }
}
