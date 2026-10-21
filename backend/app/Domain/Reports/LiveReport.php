<?php

namespace App\Domain\Reports;

use App\Domain\Analytics\LeadFunnel;
use App\Domain\Campaigns\CampaignRevenue;
use App\Domain\Conversions\ConversionHealth;
use App\Domain\Insights\Recommendations;
use App\Domain\Leads\InterestSlaStats;
use App\Domain\Partners\CommissionLedger;
use App\Domain\Tracking\AttributionReport;
use App\Enums\QualificationStatus;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\ReportShare;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The numbers behind a shared link, computed when it is opened.
 *
 * Built from the same engine as the campaign-revenue screen, so a shared figure is the one the
 * team sees. Only the sections that are on are computed, in the order the owner chose, each over
 * its own period if it has one; and only what a section is allowed to show is returned: costs leave
 * with `kpi_cost`, revenue with `kpi_revenue`, everywhere they would otherwise appear (the funnel's
 * cost per step, the trend's spend, the platform mix, the campaign table, a recommendation that
 * quotes what a campaign spent).
 *
 * `audience` is who is asking: `public` is somebody with the link, `team` is the workspace looking
 * at its own report (the preview), which also sees the sections kept for the team.
 */
class LiveReport
{
    /** @var array<string,array<string,mixed>> CampaignRevenue builds by window, for this report */
    private array $engine = [];

    /** @return array<string,mixed> */
    public function build(ReportShare $share, string $audience = 'public'): array
    {
        $this->engine = [];
        $plan = ReportTemplates::plan($share, $audience);
        $keys = array_column($plan, 'key');
        $days = array_column($plan, 'days', 'key');
        $has = fn (string $section) => in_array($section, $keys, true);
        $money = $has('kpi_cost');
        $earnings = $has('kpi_revenue');
        $currency = $share->tenant?->settings?->currency ?? 'SAR';

        $main = $this->window($share, null);

        $out = [
            'report' => [
                'name' => $share->name,
                'template' => $share->template,
                'days' => $share->days,
                'from' => $main['from']->toDateString(),
                'to' => $main['to']->toDateString(),
                'generated_at' => now()->toIso8601String(),
                'currency' => $currency,
                'compare' => (bool) $share->compare,
                'refresh_seconds' => (int) ($share->refresh_seconds ?: 60),
            ] + ($share->compare ? ['previous' => ['from' => $main['prev_from']->toDateString(), 'to' => $main['prev_to']->toDateString()]] : []),
            'sections' => $keys,
        ];

        foreach ($keys as $section) {
            $window = $this->window($share, $days[$section] ?? null);

            $out = match ($section) {
                'kpi_volume', 'kpi_cost', 'kpi_revenue' => $this->kpis($out, $share, $section, $window),
                'funnel' => $out + ['funnel' => $this->funnel($share, $window, $money)],
                'interested' => $out + ['interested' => InterestSlaStats::for($this->leads($share, $window))],
                'qualification' => $out + ['qualification' => $this->qualification($share, $window)],
                'visits' => $out + ['visits' => $this->visits($share, $window)],
                'reservations' => $out + ['reservations' => $this->reservations($share, $window)],
                'trend' => $this->trend($out, $share, $window, $money, $earnings),
                'platforms' => $out + ['platforms' => $this->platforms($share, $window, $money, $earnings)],
                'campaigns' => $out + ['campaigns' => $this->campaigns($share, $window, $money, $earnings)],
                'attribution' => $out + ['attribution' => $this->attribution($share, $window, $earnings)],
                'team' => $out + ['team' => $this->team($window['from'], $window['to'], $share->project_id)],
                'automation' => $out + ['automation' => $this->automation($window)],
                'tracking_health' => $out + ['tracking_health' => $this->trackingHealth()],
                'statement' => $out + ['statement' => $this->statement($share, $window)],
                'insights', 'recommendations' => $this->findings($out, $share, $section, $window, $money, $currency, $audience),
                default => $out,
            };
        }

        return $out;
    }

    /**
     * The period a section covers and the one before it, the same length.
     *
     * @return array{from:CarbonImmutable,to:CarbonImmutable,days:int,prev_from:CarbonImmutable,prev_to:CarbonImmutable}
     */
    private function window(ReportShare $share, ?int $days): array
    {
        $days = max(1, $days ?? $share->days);
        $to = CarbonImmutable::today()->endOfDay();
        $from = $to->subDays($days - 1)->startOfDay();
        $prevTo = $from->subDay()->endOfDay();

        return ['from' => $from, 'to' => $to, 'days' => $days, 'prev_from' => $prevTo->subDays($days - 1)->startOfDay(), 'prev_to' => $prevTo];
    }

    /** @return array<string,mixed> CampaignRevenue for a window (or the one before it) */
    private function revenue(ReportShare $share, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->engine[$from->toDateString().'|'.$to->toDateString()] ??= (new CampaignRevenue(null))->build($from, $to, $share->project_id);
    }

    /** @return Builder<Lead> every lead that arrived in the window, within the report's project and partner */
    private function leads(ReportShare $share, array $window, bool $previous = false): Builder
    {
        [$from, $to] = $previous ? [$window['prev_from'], $window['prev_to']] : [$window['from'], $window['to']];

        return Lead::query()->reorder()->whereBetween('leads.created_at', [$from, $to])
            ->when($share->project_id, fn ($q) => $q->where('leads.project_id', $share->project_id))
            ->when($share->partner_id, fn ($q) => $q->where('leads.partner_id', $share->partner_id));
    }

    /**
     * The headline figures of one window. Volumes and revenue are every lead that arrived, whatever
     * brought it; costs divide the campaign spend by those.
     *
     * @return array<string,int|float|null>
     */
    private function figures(ReportShare $share, array $window, bool $previous): array
    {
        $counts = LeadFunnel::counts($this->leads($share, $window, $previous))['steps'];
        $earned = round(((int) $this->leads($share, $window, $previous)->where('leads.status', 'won')->sum('deal_value_minor')) / 100, 2);
        [$from, $to] = $previous ? [$window['prev_from'], $window['prev_to']] : [$window['from'], $window['to']];
        $spend = $this->revenue($share, $from, $to)['totals']['spend'];

        return [
            'leads' => $counts['leads'], 'interested' => $counts['interested'], 'visits' => $counts['visit'], 'reservations' => $counts['reservation'], 'won' => $counts['won'],
            'spend' => $spend,
            'cpl' => $spend !== null && $counts['leads'] > 0 ? round($spend / $counts['leads'], 2) : null,
            'cac' => $spend !== null && $counts['won'] > 0 ? round($spend / $counts['won'], 2) : null,
            'roas' => $spend !== null && $spend > 0 ? round($earned / $spend, 2) : null,
            'revenue' => $earned,
        ];
    }

    private const KPI_KEYS = [
        'kpi_volume' => ['leads', 'interested', 'visits', 'reservations', 'won'],
        // A cost nobody has entered is null — a dash on the page, not a zero.
        'kpi_cost' => ['spend', 'cpl', 'cac', 'roas'],
        'kpi_revenue' => ['revenue'],
    ];

    /**
     * Each group of figures is added only if its section is on: a missing key is how a hidden number
     * stays hidden. When the report compares, the same figures a period earlier come beside them.
     *
     * @param  array<string,mixed>  $out
     * @return array<string,mixed>
     */
    private function kpis(array $out, ReportShare $share, string $section, array $window): array
    {
        $keys = array_flip(self::KPI_KEYS[$section]);
        $out['kpis'] = ($out['kpis'] ?? []) + array_intersect_key($this->figures($share, $window, false), $keys);

        if ($share->compare) {
            $out['previous'] = ($out['previous'] ?? []) + array_intersect_key($this->figures($share, $window, true), $keys);
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function funnel(ReportShare $share, array $window, bool $money): array
    {
        $counts = LeadFunnel::counts($this->leads($share, $window))['steps'];
        $spend = $this->revenue($share, $window['from'], $window['to'])['totals']['spend'];
        $previous = null;

        return collect(['leads', 'qualified', 'interested', 'visit', 'reservation', 'won'])->map(function (string $step) use ($counts, &$previous, $money, $spend) {
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

    /** @return array{qualified:int,partial:int,unqualified:int,total:int,rate:?float} */
    private function qualification(ReportShare $share, array $window): array
    {
        $row = $this->leads($share, $window)->selectRaw('
            count(*) as total,
            count(*) filter (where leads.qualification_status = ?) as qualified,
            count(*) filter (where leads.qualification_status = ?) as partial', [QualificationStatus::Qualified->value, QualificationStatus::PartiallyQualified->value])->first();

        $total = (int) $row->total;

        return [
            'qualified' => (int) $row->qualified,
            'partial' => (int) $row->partial,
            'unqualified' => $total - (int) $row->qualified - (int) $row->partial,
            'total' => $total,
            'rate' => $total > 0 ? round((int) $row->qualified / $total * 100, 1) : null,
        ];
    }

    /** @return array<string,mixed> */
    private function visits(ReportShare $share, array $window): array
    {
        $visits = fn () => Appointment::query()->reorder()->whereBetween('appointments.scheduled_at', [$window['from'], $window['to']])
            ->whereHas('lead', fn ($l) => $l->when($share->project_id, fn ($q) => $q->where('leads.project_id', $share->project_id))->when($share->partner_id, fn ($q) => $q->where('leads.partner_id', $share->partner_id)));

        $row = $visits()->selectRaw("
            count(*) filter (where appointments.status in ('confirmed','rescheduled','scheduled')) as booked,
            count(*) filter (where appointments.status = 'completed') as completed,
            count(*) filter (where appointments.status = 'no_show') as no_show,
            count(*) filter (where appointments.status = 'cancelled') as cancelled")->first();

        $done = (int) $row->completed;
        $resolved = $done + (int) $row->no_show;
        $byDay = $visits()->where('appointments.status', 'completed')->selectRaw('appointments.scheduled_at::date as day, count(*) as n')->groupBy('day')->orderBy('day')->get()
            ->map(fn ($r) => ['date' => (string) $r->day, 'count' => (int) $r->n])->all();

        return [
            'booked' => (int) $row->booked, 'completed' => $done, 'no_show' => (int) $row->no_show, 'cancelled' => (int) $row->cancelled,
            'completion_rate' => $resolved > 0 ? round($done / $resolved * 100, 1) : null,
            'by_day' => $byDay,
        ];
    }

    /** @return array<string,mixed> */
    private function reservations(ReportShare $share, array $window): array
    {
        $row = Reservation::query()->reorder()->whereBetween('reservations.created_at', [$window['from'], $window['to']])
            ->whereHas('lead', fn ($l) => $l->when($share->project_id, fn ($q) => $q->where('leads.project_id', $share->project_id))->when($share->partner_id, fn ($q) => $q->where('leads.partner_id', $share->partner_id)))
            ->selectRaw("
                count(*) filter (where reservations.status = 'pending') as pending,
                count(*) filter (where reservations.status = 'confirmed') as confirmed,
                count(*) filter (where reservations.status = 'converted') as converted,
                count(*) filter (where reservations.status in ('cancelled','expired')) as cancelled")->first();

        $steps = LeadFunnel::counts($this->leads($share, $window))['steps'];
        $held = (int) $row->pending + (int) $row->confirmed + (int) $row->converted;

        return [
            'pending' => (int) $row->pending, 'confirmed' => (int) $row->confirmed, 'converted' => (int) $row->converted, 'cancelled' => (int) $row->cancelled,
            // Of the people who saw a unit, how many held one.
            'conversion_rate' => $steps['visit'] > 0 ? round(min(100, $held / $steps['visit'] * 100), 1) : null,
        ];
    }

    /**
     * The daily trend; compared with the period before, day by day, when the report compares.
     *
     * @param  array<string,mixed>  $out
     * @return array<string,mixed>
     */
    private function trend(array $out, ReportShare $share, array $window, bool $money, bool $earnings): array
    {
        $revenue = $this->revenue($share, $window['from'], $window['to']);
        $days = $this->dailyFigures($this->leads($share, $window));
        $spent = collect($revenue['trend'])->pluck('spend', 'date');

        $out['trend'] = collect($revenue['trend'])->map(function (array $day) use ($days, $spent, $money, $earnings) {
            $r = $days->get($day['date']);

            return ['date' => $day['date'], 'leads' => (int) ($r->leads ?? 0), 'interested' => (int) ($r->interested ?? 0), 'visits' => (int) ($r->visits ?? 0), 'reservations' => (int) ($r->reservations ?? 0), 'won' => (int) ($r->won ?? 0)]
                + ($money ? ['spend' => $spent[$day['date']] ?? 0] : [])
                + ($earnings ? ['revenue' => round(((int) ($r->revenue ?? 0)) / 100, 2)] : []);
        })->all();

        if ($share->compare) {
            $before = $this->dailyFigures($this->leads($share, $window, true));
            $prevRevenue = $this->revenue($share, $window['prev_from'], $window['prev_to']);
            $prevSpent = collect($prevRevenue['trend'])->pluck('spend', 'date');

            $out['previous_trend'] = collect($prevRevenue['trend'])->map(function (array $day) use ($before, $prevSpent, $money, $earnings) {
                $r = $before->get($day['date']);

                return ['date' => $day['date'], 'leads' => (int) ($r->leads ?? 0), 'interested' => (int) ($r->interested ?? 0), 'visits' => (int) ($r->visits ?? 0), 'reservations' => (int) ($r->reservations ?? 0), 'won' => (int) ($r->won ?? 0)]
                    + ($money ? ['spend' => $prevSpent[$day['date']] ?? 0] : [])
                    + ($earnings ? ['revenue' => round(((int) ($r->revenue ?? 0)) / 100, 2)] : []);
            })->all();
        }

        return $out;
    }

    /** @return Collection<string,object> */
    private function dailyFigures(Builder $leads): Collection
    {
        $stage = LeadFunnel::stages();

        return $leads->selectRaw("leads.created_at::date as day, count(*) as leads,
                count(*) filter (where {$stage['interested']}) as interested,
                count(*) filter (where {$stage['visit']}) as visits,
                count(*) filter (where {$stage['reservation']}) as reservations,
                count(*) filter (where {$stage['won']}) as won,
                coalesce(sum(deal_value_minor) filter (where {$stage['won']}), 0) as revenue")
            ->groupBy('day')->get()->keyBy(fn ($r) => (string) $r->day);
    }

    /** @return list<array<string,mixed>> */
    private function platforms(ReportShare $share, array $window, bool $money, bool $earnings): array
    {
        return collect($this->revenue($share, $window['from'], $window['to'])['mix']['platforms'])->map(fn (array $p) => array_intersect_key($p, array_flip(array_filter([
            'key', 'leads', $money ? 'spend' : null, $earnings ? 'revenue' : null,
        ]))))->all();
    }

    /** @return list<array<string,mixed>> */
    private function campaigns(ReportShare $share, array $window, bool $money, bool $earnings): array
    {
        return collect($this->revenue($share, $window['from'], $window['to'])['campaigns'])->take(10)->map(fn (array $row) => array_intersect_key($row, array_flip(array_filter([
            'name', 'platform', 'leads', 'qualified', 'interested', 'visits', 'reservations', 'won',
            $money ? 'spend' : null, $money ? 'cpl' : null, $money ? 'cac' : null, ($money && $earnings) ? 'roas' : null, $earnings ? 'revenue' : null,
        ]))))->values()->all();
    }

    /**
     * Who gets the credit, counted four ways. Channels only (never a person or a link's name),
     * the top few, with revenue only where revenue is on.
     *
     * @return array{models:array<string,list<array<string,mixed>>>}
     */
    private function attribution(ReportShare $share, array $window, bool $earnings): array
    {
        $report = app(AttributionReport::class);

        return ['models' => collect(['first', 'last', 'linear', 'position'])->mapWithKeys(fn (string $model) => [
            $model => collect($report->build($model, 'channel', Carbon::instance($window['from']), Carbon::instance($window['to']), $share->project_id)['rows'])->take(6)->map(fn (array $r) => array_intersect_key($r, array_flip(array_filter([
                'key', 'label', 'leads', 'interested', 'visits', 'reservations', 'won', $earnings ? 'revenue' : null,
            ]))))->values()->all(),
        ])->all()];
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

    /** How much of the talking the automation did, and how many people are waiting for a human. @return array<string,mixed> */
    private function automation(array $window): array
    {
        $row = LeadMessage::query()->reorder()->where('direction', 'outbound')->whereBetween('lead_messages.created_at', [$window['from'], $window['to']])
            ->selectRaw('count(*) filter (where lead_messages.user_id is null) as automated, count(*) filter (where lead_messages.user_id is not null) as human')->first();
        $automated = (int) $row->automated;
        $human = (int) $row->human;

        return [
            'automated' => $automated, 'human' => $human,
            'handled_pct' => ($automated + $human) > 0 ? round($automated / ($automated + $human) * 100, 1) : null,
            'needs_human' => Lead::query()->reorder()->whereNotNull('leads.needs_human_reason')->whereNotIn('leads.status', ['won', 'lost'])->count(),
        ];
    }

    /** What each platform's conversion feed is doing — states and counts, never a credential. @return list<array<string,mixed>> */
    private function trackingHealth(): array
    {
        return collect(app(ConversionHealth::class)->summary())->map(fn (array $p) => [
            'platform' => $p['platform'], 'state' => $p['state'],
            'sent' => (int) ($p['counts']['sent'] ?? 0), 'failed' => (int) ($p['counts']['failed'] ?? 0),
            'last_sent_at' => $p['last_sent_at'],
        ])->values()->all();
    }

    /**
     * A partner's statement: what they brought down the funnel, the terms they work on, what they are
     * owed and have been paid. Revenue and the value of each deal only if the partner may see revenue;
     * never the company's cost, other partners or the team.
     *
     * @return array<string,mixed>
     */
    private function statement(ReportShare $share, array $window): array
    {
        $partner = $share->partner;
        $steps = LeadFunnel::counts($this->leads($share, $window))['steps'];
        $ledger = (new CommissionLedger)->for([$partner->id])[$partner->id];
        $terms = CommissionLedger::terms($partner);
        $shows = (bool) $partner->show_revenue;

        return [
            'partner' => $partner->name,
            'terms' => ['type' => $terms['type'], 'percent' => $terms['percent'], 'fixed' => $terms['fixed'], 'agreed' => $terms['agreed']],
            'funnel' => ['leads' => $steps['leads'], 'qualified' => $steps['qualified'], 'visits' => $steps['visit'], 'reservations' => $steps['reservation'], 'won' => $steps['won']],
            'commission' => ['due' => $ledger['due'], 'paid' => $ledger['paid'], 'balance' => $ledger['balance']],
            'deals' => array_map(fn (array $d) => ['won_at' => $d['won_at'], 'commission' => $d['commission']] + ($shows ? ['value' => $d['value']] : []), array_slice((new CommissionLedger)->deals($partner), 0, 20)),
            'payments' => $partner->payments()->orderByDesc('paid_on')->orderByDesc('id')->limit(12)->get()->map(fn ($p) => ['paid_on' => $p->paid_on?->toDateString(), 'amount' => round($p->amount_minor / 100, 2), 'reference' => $p->reference])->all(),
        ] + ($shows ? ['revenue' => $ledger['revenue']] : []);
    }

    /**
     * Insights (what is going on) and recommendations (what to do), from the same findings. A public
     * link never carries the deep link into the product.
     *
     * @param  array<string,mixed>  $out
     * @return array<string,mixed>
     */
    private function findings(array $out, ReportShare $share, string $section, array $window, bool $money, string $currency, string $audience): array
    {
        $kind = $section === 'insights' ? 'insight' : 'recommendation';
        $revenue = $this->revenue($share, $window['from'], $window['to']);

        $out[$section] = collect((new Recommendations)->build($window['from'], $window['to'], $share->project_id, $money, $currency, $revenue, $share->partner_id))
            ->where('kind', $kind)->take(6)
            ->map(fn (array $f) => ['key' => $f['key'], 'severity' => $f['severity'], 'values' => $f['values']] + ($audience === 'team' ? ['link' => $f['link']] : []))
            ->values()->all();

        return $out;
    }
}
