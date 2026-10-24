<?php

namespace App\Domain\Funnel;

use App\Domain\Reports\ReportPeriod;
use App\Models\Campaign;
use App\Models\Project;
use App\Models\User;
use App\Support\Uuid;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * A screen's question to the funnel, read from the request: a range (a preset or two dates), the projects, platforms,
 * campaigns, sources and people. Projects, campaigns and people are named by their public ids and must be the caller's own
 * workspace's (the tenant scope on those models does that); an id that is not theirs simply matches nothing.
 */
final class FunnelRequest
{
    public const RANGES = ['today', 'yesterday', 'last_7', 'last_14', 'last_30', 'this_month', 'previous_month', 'quarter', 'custom'];

    public static function filters(Request $request, ?User $viewer = null): FunnelFilters
    {
        $range = $request->input('range');
        $from = $request->filled('date_from') ? CarbonImmutable::parse($request->input('date_from')) : null;
        $to = $request->filled('date_to') ? CarbonImmutable::parse($request->input('date_to')) : null;

        if ($range && in_array($range, self::RANGES, true) && $range !== 'custom') {
            $window = ReportPeriod::resolve($range, 30);
            [$from, $to] = [$window['from'], $window['to']];
        }

        $from ??= CarbonImmutable::now()->subDays(29);
        $to ??= CarbonImmutable::now();

        $projects = $request->filled('project_id') ? Project::query()->whereIn('uuid', Uuid::onlyValid((array) $request->input('project_id')))->pluck('id')->map(fn ($i) => (int) $i)->all() : [];
        $campaigns = $request->filled('campaign_id') ? Campaign::query()->whereIn('uuid', Uuid::onlyValid((array) $request->input('campaign_id')))->pluck('id')->map(fn ($i) => (int) $i)->all() : [];
        $reps = $request->filled('assigned_to') && ($viewer?->seesAllLeads() ?? true)
            ? User::query()->whereIn('uuid', Uuid::onlyValid((array) $request->input('assigned_to')))->pluck('id')->map(fn ($i) => (int) $i)->all() : [];

        // An id the caller named that is not theirs must not widen the question to "everything".
        if ($request->filled('project_id') && $projects === []) {
            $projects = [0];
        }

        if ($request->filled('campaign_id') && $campaigns === []) {
            $campaigns = [0];
        }

        $compare = ($request->input('compare') === 'previous_month') ? ReportPeriod::comparison($from, $to, 'previous_month') : null;

        return new FunnelFilters(
            $from, $to, $projects, null, null, null, $reps, $compare['from'] ?? null, $compare['to'] ?? null,
            array_values(array_filter((array) $request->input('platform'), 'is_string')),
            $campaigns,
            array_values(array_filter((array) $request->input('source'), 'is_string')),
        );
    }
}
