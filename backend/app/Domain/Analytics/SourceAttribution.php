<?php

namespace App\Domain\Analytics;

use App\Models\Lead;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Where leads came from, read off each lead's own platform — the attribution every workspace has, whether or not an ad
 * account is connected. Kept apart from campaign attribution (which needs synced campaigns), and named for what it is,
 * so a workspace with no ad accounts sees its real funnel by source instead of a row of zeros that reads like failure.
 */
class SourceAttribution
{
    /**
     * @return array{basis: string, rows: list<array<string, mixed>>, totals: array<string, int|float>}
     */
    public static function between(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        $scoped = fn () => Lead::query()->visibleTo($user)->whereBetween('leads.created_at', [$from, $to]);

        $platforms = $scoped()->reorder()->selectRaw("coalesce(nullif(leads.platform, ''), 'unknown') as source")->distinct()->pluck('source');

        $rows = $platforms->map(function (string $source) use ($scoped) {
            $query = $scoped()->when($source === 'unknown',
                fn ($q) => $q->where(fn ($w) => $w->whereNull('leads.platform')->orWhere('leads.platform', '')),
                fn ($q) => $q->where('leads.platform', $source));
            $steps = LeadFunnel::counts($query)['steps'];
            $revenue = round(((int) (clone $query)->reorder()->where('leads.status', 'won')->sum('leads.deal_value_minor')) / 100, 2);

            return ['source' => $source] + $steps + ['revenue' => $revenue];
        })->sortByDesc('leads')->values()->all();

        $totals = collect(LeadFunnel::STEPS)->mapWithKeys(fn ($k) => [$k => (int) collect($rows)->sum($k)])->all()
            + ['revenue' => round((float) collect($rows)->sum('revenue'), 2)];

        return ['basis' => 'lead_source', 'rows' => $rows, 'totals' => $totals];
    }
}
