<?php

namespace App\Domain\Reports;

use App\Domain\Analytics\LeadFunnel;
use App\Domain\Campaigns\CampaignRevenue;
use App\Models\Lead;
use App\Models\ReportShare;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The numbers behind a shared link, computed when it is opened.
 *
 * Built from the same engine as the campaign-revenue screen, so a shared figure is the one the
 * team sees. Only the sections that are on are computed, and only what a section is allowed to
 * show is returned: costs leave with `kpi_cost`, revenue with `kpi_revenue`, everywhere they
 * would otherwise appear (the funnel's cost per step, the trend's spend, the platform mix, the
 * campaign table).
 */
class LiveReport
{
    /** @return array<string,mixed> */
    public function build(ReportShare $share): array
    {
        $on = array_keys(array_filter($share->sections ?? []));
        $has = fn (string $section) => in_array($section, $on, true);

        $to = CarbonImmutable::today()->endOfDay();
        $from = $to->subDays(max(1, $share->days) - 1)->startOfDay();

        $revenue = (new CampaignRevenue(null))->build($from, $to, $share->project_id);
        $spend = $revenue['totals']['spend'];

        // Volumes and revenue are every lead that arrived, whatever brought it; the campaign
        // table is the part that is about campaigns. Costs divide the campaign spend by those.
        $leads = fn () => Lead::query()->reorder()->whereBetween('leads.created_at', [$from, $to])->when($share->project_id, fn ($q) => $q->where('leads.project_id', $share->project_id));
        $counts = LeadFunnel::counts($leads())['steps'];
        $earned = round(((int) $leads()->where('leads.status', 'won')->sum('deal_value_minor')) / 100, 2);
        $totals = [
            'leads' => $counts['leads'], 'interested' => $counts['interested'], 'visits' => $counts['visit'], 'reservations' => $counts['reservation'], 'won' => $counts['won'],
            'spend' => $spend,
            'cpl' => $spend !== null && $counts['leads'] > 0 ? round($spend / $counts['leads'], 2) : null,
            'cac' => $spend !== null && $counts['won'] > 0 ? round($spend / $counts['won'], 2) : null,
            'roas' => $spend !== null && $spend > 0 ? round($earned / $spend, 2) : null,
            'revenue' => $earned,
        ];
        $money = $has('kpi_cost');
        $earnings = $has('kpi_revenue');

        $out = [
            'report' => [
                'name' => $share->name,
                'template' => $share->template,
                'days' => $share->days,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'generated_at' => now()->toIso8601String(),
                'currency' => $share->tenant?->settings?->currency ?? 'SAR',
            ],
            'sections' => $on,
        ];

        // Each group of figures is added only if its section is on: a missing key is how a hidden number stays hidden.
        $kpis = [];

        if ($has('kpi_volume')) {
            $kpis += ['leads' => $totals['leads'], 'interested' => $totals['interested'], 'visits' => $totals['visits'], 'reservations' => $totals['reservations'], 'won' => $totals['won']];
        }

        if ($money) {
            // A cost nobody has entered is null — a dash on the page, not a zero.
            $kpis += ['spend' => $totals['spend'], 'cpl' => $totals['cpl'], 'cac' => $totals['cac'], 'roas' => $totals['roas']];
        }

        if ($earnings) {
            $kpis += ['revenue' => $totals['revenue']];
        }

        if ($kpis !== []) {
            $out['kpis'] = $kpis;
        }

        if ($has('funnel')) {
            $previous = null;
            $out['funnel'] = collect(['leads', 'qualified', 'interested', 'visit', 'reservation', 'won'])->map(function (string $step) use ($counts, &$previous, $money, $spend) {
                $count = $counts[$step];
                $row = [
                    'key' => $step === 'visit' ? 'visits' : ($step === 'reservation' ? 'reservations' : $step),
                    'count' => $count,
                    'rate' => $counts['leads'] > 0 ? round($count / $counts['leads'] * 100, 1) : null,
                    'lost' => $previous !== null && $previous > 0 ? round(($previous - $count) / $previous * 100, 1) : null,
                ] + ($money ? ['cost' => $spend !== null && $count > 0 ? round($spend / $count, 2) : null] : []);
                $previous = $count;

                return $row;
            })->all();
        }

        if ($has('trend')) {
            $stage = LeadFunnel::stages();
            $days = $leads()->selectRaw("leads.created_at::date as day, count(*) as leads,
                    count(*) filter (where {$stage['interested']}) as interested,
                    count(*) filter (where {$stage['visit']}) as visits,
                    count(*) filter (where {$stage['reservation']}) as reservations,
                    count(*) filter (where {$stage['won']}) as won,
                    coalesce(sum(deal_value_minor) filter (where {$stage['won']}), 0) as revenue")
                ->groupBy('day')->get()->keyBy(fn ($r) => (string) $r->day);
            $spent = collect($revenue['trend'])->pluck('spend', 'date');

            $out['trend'] = collect($revenue['trend'])->map(function (array $day) use ($days, $spent, $money, $earnings) {
                $r = $days->get($day['date']);

                return ['date' => $day['date'], 'leads' => (int) ($r->leads ?? 0), 'interested' => (int) ($r->interested ?? 0), 'visits' => (int) ($r->visits ?? 0), 'reservations' => (int) ($r->reservations ?? 0), 'won' => (int) ($r->won ?? 0)]
                    + ($money ? ['spend' => $spent[$day['date']] ?? 0] : [])
                    + ($earnings ? ['revenue' => round(((int) ($r->revenue ?? 0)) / 100, 2)] : []);
            })->all();
        }

        if ($has('platforms')) {
            $out['platforms'] = collect($revenue['mix']['platforms'])->map(fn (array $p) => array_intersect_key($p, array_flip(array_filter([
                'key', 'leads', $money ? 'spend' : null, $earnings ? 'revenue' : null,
            ]))))->all();
        }

        if ($has('campaigns')) {
            $out['campaigns'] = collect($revenue['campaigns'])->take(10)->map(fn (array $row) => array_intersect_key($row, array_flip(array_filter([
                'name', 'platform', 'leads', 'qualified', 'interested', 'visits', 'reservations', 'won',
                $money ? 'spend' : null, $money ? 'cpl' : null, $money ? 'cac' : null, ($money && $earnings) ? 'roas' : null, $earnings ? 'revenue' : null,
            ]))))->values()->all();
        }

        if ($has('team')) {
            $out['team'] = $this->team($from, $to, $share->project_id);
        }

        return $out;
    }

    /**
     * Who is carrying what: leads, how many reached each step, per person. Names only — a link
     * that leaves the building does not carry an email address or a phone number.
     *
     * @return list<array<string,mixed>>
     */
    private function team(CarbonImmutable $from, CarbonImmutable $to, ?int $projectId): array
    {
        $stage = LeadFunnel::stages();

        $rows = Lead::query()->reorder()->whereNotNull('leads.assigned_to')
            ->whereBetween('leads.created_at', [$from, $to])
            ->when($projectId, fn ($q) => $q->where('leads.project_id', $projectId))
            ->selectRaw("leads.assigned_to, count(*) as leads,
                count(*) filter (where {$stage['interested']}) as interested,
                count(*) filter (where {$stage['visit']}) as visits,
                count(*) filter (where {$stage['reservation']}) as reservations,
                count(*) filter (where {$stage['won']}) as won")
            ->groupBy('leads.assigned_to')->orderByDesc('won')->orderByDesc('leads')->limit(10)->get();

        $names = User::query()->whereIn('id', $rows->pluck('assigned_to'))->pluck('name', 'id');

        /** @var Collection<int,object> $rows */
        return $rows->map(fn ($r) => [
            'name' => $names[$r->assigned_to] ?? '—',
            'leads' => (int) $r->leads, 'interested' => (int) $r->interested, 'visits' => (int) $r->visits,
            'reservations' => (int) $r->reservations, 'won' => (int) $r->won,
        ])->values()->all();
    }
}
