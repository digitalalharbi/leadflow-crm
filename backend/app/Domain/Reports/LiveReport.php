<?php

namespace App\Domain\Reports;

use App\Domain\Analytics\LeadFunnel;
use App\Domain\Billing\Entitlements;
use App\Domain\Campaigns\CampaignRevenue;
use App\Domain\Conversions\ConversionHealth;
use App\Domain\Insights\Recommendations;
use App\Domain\Leads\InterestSlaStats;
use App\Domain\Partners\CommissionLedger;
use App\Domain\Sales\RepPerformance;
use App\Domain\Tracking\AttributionReport;
use App\Domain\Tracking\TrackingHealth;
use App\Enums\QualificationStatus;
use App\Models\Appointment;
use App\Models\AutomationActionLog;
use App\Models\ConversionExport;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\Project;
use App\Models\ReportShare;
use App\Models\Reservation;
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

    private ReportContext $ctx;

    private ReportSections $sections;

    /** @var array<string,mixed> section data already built for this report, by section and window — insights read what the page shows */
    private array $memo = [];

    /** @return array<string,mixed> */
    /**
     * @param  (callable(string):bool)|null  $allowed  for a signed-in viewer: which sections they may see at all — what
     *                                                 they may not is not built, let alone shown
     * @param  list<string>|null  $only  build just these sections (a page asks for its first screen, then the rest). Which
     *                                   figures are allowed to appear is decided from every section that is on, not from these.
     */
    public function build(ReportShare $share, string $audience = 'public', ?callable $allowed = null, ?array $only = null): array
    {
        $this->engine = [];
        $plan = array_values(array_filter(ReportTemplates::plan($share, $audience), fn (array $row) => $allowed === null || $allowed($row['key'])));
        $keys = array_column($plan, 'key');
        $days = array_column($plan, 'days', 'key');
        $has = fn (string $section) => in_array($section, $keys, true);
        // Costs leave with a section that is about costs, revenue with one that is about revenue — and with nothing else.
        $money = $has('kpi_cost') || $has('marketing') || $has('exec_marketing');
        $earnings = $has('kpi_revenue') || $has('revenue') || $has('exec_sales');
        $currency = $share->currency ?: ($share->tenant?->settings?->currency ?? 'SAR');
        $this->ctx = new ReportContext($share, $audience, $keys, $money, $earnings, $currency);
        $this->sections = new ReportSections($this->ctx);
        $this->memo = [];

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
                'period' => $share->period ?: 'last_n',
                'attribution_model' => $this->ctx->model,
                'client_name' => $share->client_name,
                'state' => $share->state ?: 'live',
                'projects' => $this->projectNames(),
                'owner' => $share->relationLoaded('creator') || $share->creator ? ($share->options['show_owner'] ?? false ? $share->creator?->name : null) : null,
                'revision' => (int) $share->revision,
            ] + ($share->compare ? ['previous' => ['from' => $main['prev_from']->toDateString(), 'to' => $main['prev_to']->toDateString()]] : []),
            'sections' => $keys,
            'notes' => $this->notes($share),
        ];

        foreach ($only === null ? $keys : array_values(array_intersect($keys, $only)) as $section) {
            $window = $this->window($share, $days[$section] ?? null);

            $out = match ($section) {
                'kpi_volume', 'kpi_cost', 'kpi_revenue' => $this->kpis($out, $share, $section, $window),
                'funnel' => $out + ['funnel' => $this->sections->funnel($window, $earnings)],
                'interested' => $out + ['interested' => InterestSlaStats::for($this->leads($share, $window))],
                'qualification' => $out + ['qualification' => $this->qualification($share, $window)],
                'visits' => $out + ['visits' => $this->visits($share, $window)],
                'reservations' => $out + ['reservations' => $this->reservations($share, $window)],
                'trend' => $this->trend($out, $share, $window, $money, $earnings),
                'platforms' => $out + ['platforms' => $this->platforms($share, $window, $money, $earnings)],
                'marketing' => $out + ['marketing' => $this->memo('marketing', $window, fn () => $this->sections->marketing($window, $this->revenue($share, $window['from'], $window['to'])))],
                'campaigns' => $out + ['campaigns' => $this->memo('campaigns', $window, fn () => $this->sections->campaigns($this->revenue($share, $window['from'], $window['to']), $share->compare ? $this->revenue($share, $window['prev_from'], $window['prev_to']) : null))],
                'sources' => $out + ['sources' => $this->memo('sources', $window, fn () => $this->sections->sources($window))],
                'sales' => $out + ['sales' => $this->memo('sales', $window, fn () => $this->sections->sales($window))],
                'lead_quality' => $out + ['lead_quality' => $this->sections->leadQuality($window)],
                'loss' => $out + ['loss' => $this->sections->loss($window)],
                'projects' => $out + ['projects' => $this->sections->projects($window, $this->revenue($share, $window['from'], $window['to']))],
                'units' => $out + ['units' => $this->sections->units($window)],
                'tracking_hub' => $out + ['tracking_hub' => $this->sections->trackingHub($window)],
                'revenue' => $out + ['revenue' => $this->sections->revenue($window, $this->revenue($share, $window['from'], $window['to']))],
                'attention' => $out + ['attention' => $this->attention($share, $window, $audience)],
                'attribution' => $out + ['attribution' => $this->attribution($share, $window, $earnings)],
                'team' => $out + ['team' => $this->sections->team($window)],
                'automation' => $out + ['automation' => $this->automation($window)],
                'tracking_health' => $out + ['tracking_health' => $this->trackingHealth()],
                'exec_marketing' => $out + ['exec_marketing' => $this->execMarketing($share, $window)],
                'exec_sales' => $out + ['exec_sales' => $this->execSales($share, $window)],
                'exec_automation' => $out + ['exec_automation' => $this->execAutomation($share, $window)],
                'exec_tracking' => $out + ['exec_tracking' => $this->execTracking($share, $window)],
                'statement' => $out + ['statement' => $this->statement($share, $window)],
                'insights', 'recommendations' => $this->findings($out, $share, $section, $window, $money, $currency, $audience),
                default => $out,
            };
        }

        return $out;
    }

    /**
     * The period a section covers and the one it is compared with.
     *
     * @return array{from:CarbonImmutable,to:CarbonImmutable,days:int,prev_from:CarbonImmutable,prev_to:CarbonImmutable}
     */
    private function window(ReportShare $share, ?int $days): array
    {
        return ReportPeriod::window($share, $days);
    }

    /** @return array<string,mixed> CampaignRevenue for a window (or the one before it) */
    private function revenue(ReportShare $share, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->engine[$from->toDateString().'|'.$to->toDateString()] ??= (new CampaignRevenue(null))->build($from, $to, $this->ctx->projectIds, null, $this->ctx->campaignFilter());
    }

    /** @return Builder<Lead> every lead that arrived in the window, within the report's project and partner */
    private function leads(ReportShare $share, array $window, bool $previous = false): Builder
    {
        return $this->ctx->leads($window, $previous);
    }

    /** @return list<array{id:string,name:string}> the projects the report covers, by name — never an internal id */
    private function projectNames(): array
    {
        return Project::query()->whereIn('id', $this->ctx->projectIds)->orderBy('name')->get(['uuid', 'name'])
            ->map(fn ($p) => ['name' => $p->name])->all();
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
            'leads' => $counts['leads'], 'qualified' => $counts['qualified'], 'interested' => $counts['interested'], 'visits' => $counts['visit'], 'reservations' => $counts['reservation'], 'won' => $counts['won'],
            'conversion_rate' => $counts['leads'] > 0 ? round($counts['won'] / $counts['leads'] * 100, 2) : null,
            'spend' => $spend,
            'cpl' => $spend !== null && $counts['leads'] > 0 ? round($spend / $counts['leads'], 2) : null,
            // What one held viewing cost (spend / viewings). Not called CPA: that word needs its conversion event stated.
            'cost_per_viewing' => $spend !== null && $counts['visit'] > 0 ? round($spend / $counts['visit'], 2) : null,
            'cac' => $spend !== null && $counts['won'] > 0 ? round($spend / $counts['won'], 2) : null,
            'roas' => $spend !== null && $spend > 0 ? round($earned / $spend, 2) : null,
            'revenue' => $earned,
        ];
    }

    private const KPI_KEYS = [
        'kpi_volume' => ['leads', 'qualified', 'interested', 'visits', 'reservations', 'won', 'conversion_rate'],
        // A cost nobody has entered is null — a dash on the page, not a zero.
        'kpi_cost' => ['spend', 'cpl', 'cost_per_viewing', 'cac', 'roas'],
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
            ->whereHas('lead', fn ($l) => $this->ctx->narrow($l));

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
            ->whereHas('lead', fn ($l) => $this->ctx->narrow($l))
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
     * Who gets the credit, under the model the report was made with — and that model is always named. Channels only
     * (never a person or a link's name), the top few, with revenue only where revenue is on. The other models are
     * side by side only on a plan that includes advanced attribution.
     *
     * @return array{model:string,rows:list<array<string,mixed>>,models?:array<string,list<array<string,mixed>>>}
     */
    private function attribution(ReportShare $share, array $window, bool $earnings): array
    {
        $report = app(AttributionReport::class);
        $filters = array_filter([
            'platforms' => $this->ctx->leadPlatforms(), 'campaign_ids' => $this->ctx->campaignIds,
            'sources' => $this->ctx->sources, 'rep_ids' => $this->ctx->salesUserIds, 'partner_id' => $this->ctx->partnerId(),
        ]);
        $rows = fn (string $model) => collect($report->build($model, 'channel', Carbon::instance($window['from']), Carbon::instance($window['to']), $this->ctx->projectIds, $filters)['rows'])
            ->take(8)->map(fn (array $r) => array_intersect_key($r, array_flip(array_filter([
                'key', 'label', 'leads', 'qualified', 'interested', 'visits', 'reservations', 'won', $earnings ? 'revenue' : null,
            ]))))->values()->all();

        $out = ['model' => $this->ctx->model, 'rows' => $rows($this->ctx->model)];

        if ($share->tenant && Entitlements::for($share->tenant)->has('advanced_attribution')) {
            $out['models'] = collect(['first', 'last', 'linear', 'position'])->mapWithKeys(fn (string $m) => [$m => $m === $this->ctx->model ? $out['rows'] : $rows($m)])->all();
        }

        return $out;
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
    /**
     * The management report's marketing page: what the ads cost and what each step of the way cost,
     * with the period before beside it when the report compares.
     *
     * @return array{figures:array<string,int|float|null>,previous:?array<string,int|float|null>}
     */
    private function execMarketing(ReportShare $share, array $window): array
    {
        $keys = ['spend', 'leads', 'cpl', 'qualified', 'cpql', 'interested', 'visits', 'reservations', 'cac', 'roas'];
        $pick = fn (CarbonImmutable $from, CarbonImmutable $to) => array_intersect_key($this->revenue($share, $from, $to)['totals'], array_flip($keys));

        return [
            'figures' => $pick($window['from'], $window['to']),
            'previous' => $share->compare ? $pick($window['prev_from'], $window['prev_to']) : null,
        ];
    }

    /**
     * The sales page: the whole team as one — what was assigned, how fast it was answered and how it ended.
     *
     * @return array{figures:array<string,int|float|null>,previous:?array<string,int|float|null>}
     */
    private function execSales(ReportShare $share, array $window): array
    {
        $team = new RepPerformance;

        return [
            'figures' => $team->teamSummary($window['from'], $window['to'], $this->ctx->projectIds),
            'previous' => $share->compare ? $team->teamSummary($window['prev_from'], $window['prev_to'], $this->ctx->projectIds) : null,
        ];
    }

    /**
     * The automation page: how much of the talking it did, how often it worked, and what it handed to a person.
     *
     * @return array{figures:array<string,int|float|null>,previous:?array<string,int|float|null>}
     */
    private function execAutomation(ReportShare $share, array $window): array
    {
        $at = function (CarbonImmutable $from, CarbonImmutable $to): array {
            $logs = AutomationActionLog::query()->whereBetween('happened_at', [$from, $to]);
            $completed = (clone $logs)->where('result', AutomationActionLog::COMPLETED)->count();
            $failed = (clone $logs)->where('result', AutomationActionLog::FAILED)->count();

            return [
                'coverage' => $this->automation(['from' => $from, 'to' => $to])['handled_pct'],
                'success' => $completed + $failed > 0 ? round($completed / ($completed + $failed) * 100, 1) : null,
                'failures' => $failed,
            ];
        };
        $needsHuman = Lead::query()->reorder()->whereNotNull('leads.needs_human_reason')->whereNotIn('leads.status', ['won', 'lost'])->count();

        return [
            'figures' => $at($window['from'], $window['to']) + ['needs_human' => $needsHuman],
            // What needs a person is a state, not a flow: there is no "last month's" of it to compare.
            'previous' => $share->compare ? $at($window['prev_from'], $window['prev_to']) : null,
        ];
    }

    /**
     * The tracking page: how much of what arrived can be traced, how clean the UTMs are, and whether the
     * sales that were told back to the platforms were accepted.
     *
     * @return array{figures:array<string,int|float|null>,previous:?array<string,int|float|null>}
     */
    private function execTracking(ReportShare $share, array $window): array
    {
        $health = app(TrackingHealth::class)->build(max(1, (int) $window['days']))['summary'];
        $offline = function (CarbonImmutable $from, CarbonImmutable $to): array {
            $rows = ConversionExport::query()->whereBetween('created_at', [$from, $to])->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
            $sent = (int) ($rows[ConversionExport::SENT] ?? 0);
            $failed = (int) ($rows[ConversionExport::FAILED] ?? 0);

            return ['offline_sent' => $sent, 'offline_failed' => $failed, 'offline_success' => $sent + $failed > 0 ? round($sent / ($sent + $failed) * 100, 1) : null];
        };

        return [
            'figures' => [
                'attribution' => $health['unattributed_percent'] !== null ? round(100 - (float) $health['unattributed_percent'], 1) : null,
                'utm_health' => $health['missing_utm_percent'] !== null ? round(100 - (float) $health['missing_utm_percent'], 1) : null,
                'unattributed' => $health['unattributed_percent'],
            ] + $offline($window['from'], $window['to']),
            'previous' => $share->compare ? $offline($window['prev_from'], $window['prev_to']) : null,
        ];
    }

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
     * Insights (what is going on) and recommendations (what to do). Insights are drawn only from the sections that are
     * on, so a hidden section can not be read out of a sentence. A public link never carries the deep link into the product.
     *
     * @param  array<string,mixed>  $out
     * @return array<string,mixed>
     */
    private function findings(array $out, ReportShare $share, string $section, array $window, bool $money, string $currency, string $audience): array
    {
        $kind = $section === 'insights' ? 'insight' : 'recommendation';
        $revenue = $this->revenue($share, $window['from'], $window['to']);

        $found = collect((new Recommendations)->build($window['from'], $window['to'], $this->ctx->projectIds, $money, $currency, $revenue, $share->partner_id))
            ->where('kind', $kind)->take(6)
            ->map(fn (array $f) => ['key' => $f['key'], 'severity' => $f['severity'], 'values' => $f['values']] + ($audience === 'team' ? ['link' => $f['link']] : []))
            ->values();

        if ($kind === 'insight') {
            $found = $found->concat($this->derivedInsights($share, $window))->unique('key')->take(8)->values();
        }

        $out[$section] = $found->all();

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function derivedInsights(ReportShare $share, array $window): array
    {
        $on = fn (string $s) => $this->ctx->has($s);
        $figures = $this->figures($share, $window, false);
        $before = $share->compare ? $this->figures($share, $window, true) : [];
        $marketing = $on('marketing') ? $this->memo('marketing', $window, fn () => $this->sections->marketing($window, $this->revenue($share, $window['from'], $window['to']))) : [];
        $sources = $on('sources') ? $this->memo('sources', $window, fn () => $this->sections->sources($window))['rows'] : [];
        $campaigns = $on('campaigns') ? $this->memo('campaigns', $window, fn () => $this->sections->campaigns($this->revenue($share, $window['from'], $window['to']))) : [];
        $sales = $on('sales') ? $this->memo('sales', $window, fn () => $this->sections->sales($window)) : [];
        $salesBefore = $on('sales') && $share->compare ? $this->sections->sales(['from' => $window['prev_from'], 'to' => $window['prev_to']] + $window) : [];
        $volume = $on('kpi_volume') || $on('funnel');

        return $this->sections->findings(
            $marketing, $sources, $campaigns, $sales, $salesBefore,
            $volume ? $figures : [], $volume ? $before : [],
        );
    }

    /** @return array<string,mixed> */
    private function memo(string $name, array $window, callable $build): mixed
    {
        return $this->memo[$name.'|'.$window['from']->toDateString().'|'.$window['to']->toDateString()] ??= $build();
    }

    /** @return list<array{kind:string,body:string,by:?string,at:string}> what the team wrote for the reader of this report */
    private function notes(ReportShare $share): array
    {
        return $share->notes()->with('author:id,name')->get()->map(fn ($n) => ['kind' => $n->kind, 'body' => $n->body, 'at' => $n->created_at?->toIso8601String()])->values()->all();
    }

    /** @return list<array<string,mixed>> */
    private function attention(ReportShare $share, array $window, string $audience): array
    {
        $sales = $this->memo('sales', $window, fn () => $this->sections->sales($window));

        return $this->sections->attention($window, $sales, $this->figures($share, $window, false), $share->compare ? $this->figures($share, $window, true) : [], $audience === 'team');
    }
}
