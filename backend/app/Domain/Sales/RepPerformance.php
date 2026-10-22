<?php

namespace App\Domain\Sales;

use App\Domain\Analytics\LeadFunnel;
use App\Domain\Automation\Thresholds;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * How each sales rep is doing, and — the part a bare league table gets wrong — how they are doing
 * for the leads they were given.
 *
 * A rep with six leads and two sales is not "worse" than one with sixty and nine. So every row
 * carries its volume and its share of the team's, and the ranking is by sales *above what the team's
 * own close rate would have produced from that many leads*: the number a manager can act on. A rep
 * on a small sample is marked as one, and the ranking puts them after those with enough leads to
 * judge.
 *
 * Every rate follows the same funnel steps as the rest of the product (LeadFunnel), so a figure here
 * is the figure on the dashboard.
 */
class RepPerformance
{
    /** Below this many leads a rate says more about luck than about the rep. */
    public const SMALL_SAMPLE = 10;

    /** @return array{reps:list<array<string,mixed>>,team:array<string,mixed>,from:string,to:string} */
    public function build(CarbonInterface $from, CarbonInterface $to, ?int $projectId = null, bool $withMoney = true): array
    {
        $stage = LeadFunnel::stages();
        $target = (int) Thresholds::workspace('sla_minutes');

        $rows = Lead::query()->reorder()
            ->whereNotNull('leads.assigned_to')
            ->whereBetween('leads.created_at', [$from, $to])
            ->when($projectId, fn ($q) => $q->where('leads.project_id', $projectId))
            ->selectRaw("leads.assigned_to,
                count(*) as leads,
                count(*) filter (where leads.first_contact_at is not null) as contacted,
                count(*) filter (where leads.first_contact_at is not null and leads.assigned_at is not null and leads.first_contact_at <= leads.assigned_at + make_interval(mins => ?)) as in_time,
                percentile_cont(0.5) within group (order by extract(epoch from (leads.first_contact_at - leads.assigned_at)) / 60)
                    filter (where leads.first_contact_at is not null and leads.assigned_at is not null and leads.first_contact_at >= leads.assigned_at) as response_minutes,
                count(*) filter (where {$stage['interested']}) as interested,
                count(*) filter (where {$stage['visit']}) as visits,
                count(*) filter (where {$stage['reservation']}) as reservations,
                count(*) filter (where {$stage['won']}) as won,
                coalesce(sum(leads.deal_value_minor) filter (where {$stage['won']}), 0) as revenue_minor", [$target])
            ->groupBy('leads.assigned_to')->get();

        $people = User::query()->whereIn('id', $rows->pluck('assigned_to'))->get(['id', 'uuid', 'name'])->keyBy('id');
        $followUps = $this->followUps($rows->pluck('assigned_to')->all(), $from, $to);

        $totals = [
            'leads' => (int) $rows->sum('leads'), 'won' => (int) $rows->sum('won'), 'visits' => (int) $rows->sum('visits'),
            'reservations' => (int) $rows->sum('reservations'), 'interested' => (int) $rows->sum('interested'),
            'contacted' => (int) $rows->sum('contacted'), 'in_time' => (int) $rows->sum('in_time'),
        ];
        $teamClose = $totals['leads'] > 0 ? $totals['won'] / $totals['leads'] : 0.0;

        $reps = $rows->map(function ($r) use ($people, $totals, $teamClose, $followUps, $withMoney) {
            $leads = (int) $r->leads;
            $won = (int) $r->won;
            $expected = $leads * $teamClose;

            $row = [
                'id' => $people[$r->assigned_to]->uuid ?? null,
                'name' => $people[$r->assigned_to]->name ?? '—',
                'leads' => $leads,
                'volume_share' => $totals['leads'] > 0 ? round($leads / $totals['leads'] * 100, 1) : null,
                'small_sample' => $leads < self::SMALL_SAMPLE,
                'response_minutes' => $r->response_minutes !== null ? (int) round((float) $r->response_minutes) : null,
                'contact_rate' => $this->pct((int) $r->contacted, $leads),
                'sla_compliance' => $this->pct((int) $r->in_time, $leads),
                'interested_rate' => $this->pct((int) $r->interested, $leads),
                'visit_rate' => $this->pct((int) $r->visits, (int) $r->interested),
                'reservation_rate' => $this->pct((int) $r->reservations, (int) $r->visits),
                'close_rate' => $this->pct($won, $leads),
                'won' => $won,
                'visits' => (int) $r->visits,
                'reservations' => (int) $r->reservations,
                'follow_up_completion' => $followUps[$r->assigned_to] ?? null,
                // Sales above what the team's own close rate would have made of this many leads.
                'won_vs_expected' => round($won - $expected, 1),
            ];

            return $withMoney ? $row + ['revenue' => round(((int) $r->revenue_minor) / 100, 2)] : $row;
        });

        // Those with enough leads to judge come first, ordered by how far above (or below) expectation they are.
        $ranked = $reps->sortBy([
            fn ($a, $b) => $a['small_sample'] <=> $b['small_sample'],
            fn ($a, $b) => $b['won_vs_expected'] <=> $a['won_vs_expected'],
            fn ($a, $b) => $b['leads'] <=> $a['leads'],
        ])->values()->map(fn (array $row, int $i) => ['rank' => $i + 1] + $row);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'reps' => $ranked->all(),
            'team' => [
                'leads' => $totals['leads'], 'won' => $totals['won'],
                'close_rate' => $this->pct($totals['won'], $totals['leads']),
                'contact_rate' => $this->pct($totals['contacted'], $totals['leads']),
                'sla_compliance' => $this->pct($totals['in_time'], $totals['leads']),
                'interested_rate' => $this->pct($totals['interested'], $totals['leads']),
                'visit_rate' => $this->pct($totals['visits'], $totals['interested']),
                'reservation_rate' => $this->pct($totals['reservations'], $totals['visits']),
                'sla_target_minutes' => $target,
            ],
        ];
    }

    /**
     * The whole team as one line: what was assigned, how much of it was reached and how fast, and how it
     * ended. The management report's sales page, and the same funnel steps as everywhere else.
     *
     * @return array<string,int|float|null>
     */
    public function teamSummary(CarbonInterface $from, CarbonInterface $to, ?int $projectId = null): array
    {
        $stage = LeadFunnel::stages();
        $target = (int) Thresholds::workspace('sla_minutes');

        $r = Lead::query()->reorder()->whereNotNull('leads.assigned_to')->whereBetween('leads.created_at', [$from, $to])
            ->when($projectId, fn ($q) => $q->where('leads.project_id', $projectId))
            ->selectRaw("count(*) as assigned,
                count(*) filter (where leads.first_contact_at is not null) as contacted,
                count(*) filter (where leads.first_contact_at is not null and leads.assigned_at is not null and leads.first_contact_at <= leads.assigned_at + make_interval(mins => ?)) as in_time,
                percentile_cont(0.5) within group (order by extract(epoch from (leads.first_contact_at - leads.assigned_at)) / 60)
                    filter (where leads.first_contact_at is not null and leads.assigned_at is not null and leads.first_contact_at >= leads.assigned_at) as response_minutes,
                count(*) filter (where {$stage['visit']}) as visits,
                count(*) filter (where {$stage['reservation']}) as reservations,
                count(*) filter (where {$stage['won']}) as won,
                coalesce(sum(leads.deal_value_minor) filter (where {$stage['won']}), 0) as revenue_minor", [$target])->first();

        $due = FollowUp::query()->whereBetween('due_at', [$from, min($to, now())])->where('status', '!=', 'cancelled')
            ->when($projectId, fn ($q) => $q->whereHas('lead', fn ($l) => $l->where('leads.project_id', $projectId)))
            ->selectRaw("count(*) as due, count(*) filter (where status = 'completed') as done")->first();

        $assigned = (int) $r->assigned;

        return [
            'assigned' => $assigned,
            'contacted' => (int) $r->contacted,
            'response_minutes' => $r->response_minutes !== null ? (int) round((float) $r->response_minutes) : null,
            'follow_up_completion' => $this->pct((int) $due->done, (int) $due->due),
            'visits' => (int) $r->visits,
            'reservations' => (int) $r->reservations,
            'won' => (int) $r->won,
            'revenue' => round(((int) $r->revenue_minor) / 100, 2),
            'close_rate' => $this->pct((int) $r->won, $assigned),
            'sla' => $this->pct((int) $r->in_time, $assigned),
        ];
    }

    /**
     * The share of follow-ups that came due in the period and were done — per rep.
     *
     * @param  list<int>  $ids
     * @return Collection<int,float|null>
     */
    private function followUps(array $ids, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return FollowUp::query()->whereIn('assigned_to', $ids)->whereBetween('due_at', [$from, min($to, now())])
            ->where('status', '!=', 'cancelled')
            ->selectRaw("assigned_to, count(*) as due, count(*) filter (where status = 'completed') as done")
            ->groupBy('assigned_to')->get()
            ->mapWithKeys(fn ($r) => [$r->assigned_to => $this->pct((int) $r->done, (int) $r->due)]);
    }

    private function pct(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }
}
