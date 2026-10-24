<?php

namespace App\Domain\Campaigns;

use App\Domain\Analytics\LeadFunnel;
use App\Models\Campaign;
use App\Models\CampaignSpend;
use App\Models\Lead;
use App\Models\MetricSnapshot;
use App\Models\User;
use App\Support\ProjectIds;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * From the riyal spent to the riyal earned, on one screen's worth of data.
 *
 * Everything here is built from the same two sources the campaign list uses (the ad account's
 * spend, the CRM's leads) and the same five funnel steps, so a number on this page is the number
 * on the list: the funnel, the table, the heatmap and the trend cannot disagree.
 *
 *   funnel       how many reached each step, what each one cost, and where they were lost
 *   campaigns    the table: spend → leads → qualified → interested → visits → reservations → won
 *                → revenue, with the cost of getting one person to each step, CAC and ROAS
 *   heatmap      the share of each campaign's leads that reached each step — the shape of its funnel
 *   trend        by day: spend and what that day's leads became
 *   mix          where the leads, the spend and the revenue came from
 *   comparison   this period against the one before it, same length
 *   rankings     best return, cheapest lead, most reservations, spend that bought nothing
 */
class CampaignRevenue
{
    public const STEPS = ['leads', 'qualified', 'interested', 'visits', 'reservations', 'won'];

    /** The figures compared across periods. */
    private const COMPARED = ['spend', 'leads', 'qualified', 'interested', 'visits', 'reservations', 'won', 'revenue', 'cpl', 'cpql', 'cost_per_interested', 'cost_per_visit', 'cost_per_reservation', 'cac', 'roas'];

    public function __construct(private ?User $user) {}

    /** @return array<string,mixed> */
    /**
     * @param  array{platforms?: list<string>, campaign_ids?: list<int>}  $only  narrows to these platforms / campaigns
     */
    public function build(CarbonInterface $from, CarbonInterface $to, int|array|null $projectId = null, ?string $platform = null, array $only = []): array
    {
        $filters = ['project_ids' => ProjectIds::list($projectId), 'platform' => $platform] + $only;

        $metrics = new CampaignMetrics($this->user, $from, $to);
        $rows = collect($metrics->rows($filters));
        $totals = $metrics->totals($rows->all());

        // The period before, the same length.
        $days = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $prevTo = CarbonImmutable::parse($from)->subDay()->endOfDay();
        $prevFrom = $prevTo->subDays($days - 1)->startOfDay();
        $previous = new CampaignMetrics($this->user, $prevFrom, $prevTo);
        $previousTotals = $previous->totals($previous->rows($filters));

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $days,
            'totals' => $totals,
            'comparison' => $this->comparison($totals, $previousTotals),
            'funnel' => $this->funnel($totals),
            'campaigns' => $rows->take(30)->values()->all(),
            'heatmap' => $this->heatmap($rows),
            'mix' => $this->mix($rows),
            'rankings' => $this->rankings($rows),
            'trend' => $this->trend($rows, $from, $to),
        ];
    }

    /**
     * @param  array<string,mixed>  $now
     * @param  array<string,mixed>  $before
     * @return array<string,array{current:float|int|null, previous:float|int|null, change:?float}>
     */
    private function comparison(array $now, array $before): array
    {
        return collect(self::COMPARED)->mapWithKeys(function (string $key) use ($now, $before) {
            $a = $now[$key] ?? null;
            $b = $before[$key] ?? null;

            return [$key => [
                'current' => $a,
                'previous' => $b,
                // Null when there is nothing to compare against: "+∞%" is not an answer.
                'change' => $a !== null && $b !== null && (float) $b != 0.0 ? round(((float) $a - (float) $b) / (float) $b * 100, 1) : null,
            ]];
        })->all();
    }

    /** @return list<array{key:string,count:int,cost:?float,rate:?float,lost:?float}> */
    private function funnel(array $totals): array
    {
        $leads = (int) $totals['leads'];
        $spend = $totals['spend'];
        $previous = null;

        return collect(self::STEPS)->map(function (string $step) use ($totals, $leads, $spend, &$previous) {
            $count = (int) $totals[$step];
            $row = [
                'key' => $step,
                'count' => $count,
                // What one person at this step cost.
                'cost' => $spend !== null && $count > 0 ? round($spend / $count, 2) : null,
                'rate' => $leads > 0 ? round($count / $leads * 100, 1) : null,
                // The share of the step before that never made it here.
                'lost' => $previous !== null && $previous > 0 ? round(($previous - $count) / $previous * 100, 1) : null,
            ];
            $previous = $count;

            return $row;
        })->all();
    }

    /**
     * Campaigns × steps, each cell the share of that campaign's leads that got there. The eight
     * campaigns with the most leads: a heatmap of forty rows is a table.
     *
     * @param  Collection<int,array<string,mixed>>  $rows
     */
    private function heatmap(Collection $rows): array
    {
        $top = $rows->where('leads', '>', 0)->sortByDesc('leads')->take(8)->values();

        return [
            'steps' => array_slice(self::STEPS, 1),
            'rows' => $top->map(fn (array $row) => [
                'id' => $row['id'],
                'name' => $row['name'],
                'platform' => $row['platform'],
                'leads' => $row['leads'],
                'cells' => collect(array_slice(self::STEPS, 1))->map(fn (string $step) => round($row[$step] / $row['leads'] * 100, 1))->all(),
            ])->all(),
        ];
    }

    /** @param  Collection<int,array<string,mixed>>  $rows */
    private function mix(Collection $rows): array
    {
        $byPlatform = $rows->groupBy('platform')->map(fn (Collection $group, string $platform) => [
            'key' => $platform,
            'leads' => $group->sum('leads'),
            'spend' => round((float) $group->sum('spend'), 2),
            'revenue' => round((float) $group->sum('revenue'), 2),
        ])->values();

        // Revenue by campaign: the top five and "the rest".
        $revenue = $rows->where('revenue', '>', 0)->sortByDesc('revenue')->values();
        $head = $revenue->take(5)->map(fn (array $r) => ['key' => $r['id'], 'label' => $r['name'], 'value' => $r['revenue']]);
        $tail = round((float) $revenue->slice(5)->sum('revenue'), 2);

        return [
            'platforms' => $byPlatform->all(),
            'revenue_by_campaign' => $head->when($tail > 0, fn ($c) => $c->push(['key' => 'other', 'label' => null, 'value' => $tail]))->values()->all(),
        ];
    }

    /** @param  Collection<int,array<string,mixed>>  $rows */
    private function rankings(Collection $rows): array
    {
        $pick = fn (Collection $c, int $n = 3) => $c->take($n)->map(fn (array $r) => [
            'id' => $r['id'], 'name' => $r['name'], 'platform' => $r['platform'],
            'spend' => $r['spend'], 'leads' => $r['leads'], 'reservations' => $r['reservations'], 'won' => $r['won'],
            'revenue' => $r['revenue'], 'cpl' => $r['cpl'], 'roas' => $r['roas'],
        ])->values()->all();

        return [
            'roas' => $pick($rows->filter(fn ($r) => $r['roas'] !== null && $r['roas'] > 0)->sortByDesc('roas')),
            'cpl' => $pick($rows->filter(fn ($r) => $r['cpl'] !== null && $r['leads'] >= 3)->sortBy('cpl')),
            'reservations' => $pick($rows->filter(fn ($r) => $r['reservations'] > 0)->sortByDesc('reservations')),
            // Money out and nobody qualified: the first thing to stop.
            'wasted' => $pick($rows->filter(fn ($r) => ($r['spend'] ?? 0) > 0 && $r['qualified'] === 0)->sortByDesc('spend')),
        ];
    }

    /**
     * Day by day: what was spent, and what that day's leads became. The funnel columns follow the
     * day the lead arrived (a cohort), so the last week reads low until its leads have had time
     * to qualify — which is the honest shape of the data, not a gap.
     *
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function trend(Collection $rows, CarbonInterface $from, CarbonInterface $to): array
    {
        $campaignIds = Campaign::query()->whereIn('uuid', $rows->pluck('id'))->pluck('id', 'uuid');
        $manualNames = $rows->where('spend_source', 'manual')->pluck('name');

        $spend = MetricSnapshot::query()->whereIn('campaign_id', $campaignIds->values())
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('date, sum(spend_minor) as spend')->groupBy('date')->pluck('spend', 'date');

        $manual = $manualNames->isEmpty() ? collect() : CampaignSpend::query()->whereIn('campaign_name', $manualNames)
            ->whereBetween('spend_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('spend_date, sum(amount_minor) as spend')->groupBy('spend_date')->pluck('spend', 'spend_date');

        $stage = LeadFunnel::stages();
        $query = Lead::query();
        $crm = ($this->user ? $query->visibleTo($this->user) : $query)->reorder()
            ->whereIn('campaign_ref_id', $campaignIds->values())
            ->whereBetween('leads.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw("leads.created_at::date as day, count(*) as leads,
                count(*) filter (where {$stage['interested']}) as interested,
                count(*) filter (where {$stage['visit']}) as visits,
                count(*) filter (where {$stage['reservation']}) as reservations,
                count(*) filter (where {$stage['won']}) as won,
                coalesce(sum(deal_value_minor) filter (where {$stage['won']}), 0) as revenue")
            ->groupBy('day')->get()->keyBy(fn ($r) => (string) $r->day);

        $out = [];

        for ($day = CarbonImmutable::parse($from)->startOfDay(); $day->lte($to); $day = $day->addDay()) {
            $key = $day->toDateString();
            $r = $crm->get($key);

            $out[] = [
                'date' => $key,
                'spend' => round((((int) ($spend[$key] ?? 0)) + ((int) ($manual[$key] ?? 0))) / 100, 2),
                'leads' => (int) ($r->leads ?? 0),
                'interested' => (int) ($r->interested ?? 0),
                'visits' => (int) ($r->visits ?? 0),
                'reservations' => (int) ($r->reservations ?? 0),
                'won' => (int) ($r->won ?? 0),
                'revenue' => round(((int) ($r->revenue ?? 0)) / 100, 2),
            ];
        }

        return $out;
    }
}
