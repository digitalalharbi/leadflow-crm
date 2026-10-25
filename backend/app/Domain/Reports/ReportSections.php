<?php

namespace App\Domain\Reports;

use App\Domain\Analytics\LeadFunnel;
use App\Domain\Automation\Thresholds;
use App\Domain\Campaigns\CampaignMetrics;
use App\Domain\Funnel\RealEstateFunnel;
use App\Models\Appointment;
use App\Models\AutomationActionLog;
use App\Models\ConversionExport;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Property;
use App\Models\TrackingEvent;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The sections of a report that are built from the lead trail: marketing, sources, sales, the team, lead quality,
 * losses, projects, units, the tracking hub, revenue, what needs attention, and the findings.
 *
 * Every figure here is counted from what Mohtam recorded, inside the report's workspace, projects and filters. A figure
 * that cannot be counted is `null` — never a zero standing in for "unknown" — and a section with nothing behind it says
 * so (`available: false`) instead of drawing an empty table. Money and revenue are added only when the report may show them.
 */
class ReportSections
{
    /** The ad platforms Mohtam can hold spend for. Everything else is a source, not a platform. */
    private const AD_PLATFORMS = ['meta', 'facebook', 'instagram', 'tiktok', 'snapchat', 'google_ads', 'linkedin', 'x'];

    /**
     * Where a lead came from, in plain categories: the ad platform it was bought on, otherwise what the source says.
     * One definition, so a source is the same thing in the marketing page, the sources page and the revenue page.
     */
    public static function channelSql(): string
    {
        return "case
            when lower(leads.platform) in ('meta','facebook','instagram') then 'meta'
            when lower(leads.platform) in ('tiktok','snapchat','google_ads','linkedin','x') then lower(leads.platform)
            when leads.partner_id is not null then 'broker'
            when lower(coalesce(leads.source,'')) like '%whatsapp%' then 'whatsapp'
            when lower(coalesce(leads.source,'')) like '%qr%' then 'qr'
            when lower(coalesce(leads.source,'')) similar to '%(referral|referred|إحالة)%' then 'referral'
            when lower(coalesce(leads.source,'')) like '%broker%' or lower(coalesce(leads.source,'')) like '%partner%' then 'broker'
            when lower(coalesce(leads.source,'')) like '%influenc%' then 'influencer'
            when lower(coalesce(leads.source,'')) similar to '%(portal|aqar|bayut|haraj|property finder)%' then 'portals'
            when lower(coalesce(leads.source,'')) similar to '%(organic|seo)%' then 'organic'
            when lower(coalesce(leads.source,'')) like '%social%' then 'social'
            when lower(coalesce(leads.platform,'')) = 'landing_page' then 'landing_page'
            when lower(coalesce(leads.platform,'')) in ('website','webhook','google_sheets') then 'form'
            when lower(coalesce(leads.platform,'')) = 'import' then 'import'
            when lower(coalesce(leads.platform,'')) = 'manual' then 'manual'
            else 'other' end";
    }

    public function __construct(private ReportContext $ctx) {}

    // ---------------------------------------------------------------- marketing (§15, §16)

    /**
     * Two blocks that are never mixed: what the ad platforms reported (spend, impressions, clicks), and what Mohtam
     * attributed to each channel (leads and what became of them). A channel appears only if it has data.
     *
     * @param  array<string,mixed>  $revenue  CampaignRevenue for the window
     * @return array<string,mixed>
     */
    public function marketing(array $window, array $revenue): array
    {
        $byPlatform = collect($revenue['campaigns'] ?? [])->groupBy('platform');
        $reported = $byPlatform->map(function (Collection $rows, string $platform) {
            $spend = $rows->filter(fn ($r) => $r['spend'] !== null);
            $impressions = $rows->filter(fn ($r) => $r['impressions'] !== null)->sum('impressions');
            $clicks = $rows->filter(fn ($r) => $r['clicks'] !== null)->sum('clicks');
            $has = $rows->contains(fn ($r) => $r['impressions'] !== null || $r['spend'] !== null);

            if (! $has) {
                return null;
            }

            $cost = $spend->isEmpty() ? null : round((float) $spend->sum('spend'), 2);

            return array_filter([
                'key' => $platform,
                'spend' => $this->ctx->money ? $cost : false,
                'impressions' => $impressions > 0 ? (int) $impressions : null,
                'clicks' => $clicks > 0 ? (int) $clicks : null,
                'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : null,
                'cpc' => $this->ctx->money && $cost !== null && $clicks > 0 ? round($cost / $clicks, 2) : false,
            ], fn ($v) => $v !== false);
        })->filter()->values()->all();

        $spendBy = collect($reported)->mapWithKeys(fn ($r) => [$r['key'] => $r['spend'] ?? null]);
        $attributed = $this->byChannel($window)->map(function (array $row) use ($spendBy) {
            $spend = $this->ctx->money ? ($spendBy[$row['key']] ?? null) : null;
            $out = $row;

            if ($this->ctx->money) {
                $out['cpl'] = $spend !== null && $row['leads'] > 0 ? round($spend / $row['leads'], 2) : null;
                $out['cac'] = $spend !== null && $row['won'] > 0 ? round($spend / $row['won'], 2) : null;
                $out['roas'] = $this->ctx->earnings && $spend !== null && $spend > 0 && isset($row['revenue']) ? round($row['revenue'] / $spend, 2) : null;
            }

            return $out;
        })->values()->all();

        return [
            // Has any ad account reported anything at all? Without one, the page says what it is showing instead.
            'has_ad_data' => $reported !== [],
            'platform_reported' => $reported,
            'mohtam_attributed' => $attributed,
        ];
    }

    /** @return Collection<int,array<string,mixed>> */
    private function byChannel(array $window): Collection
    {
        $stage = LeadFunnel::stages();
        $rows = $this->ctx->leads($window)->selectRaw($this->channelSql()." as channel, count(*) as leads,
                count(*) filter (where {$stage['qualified']}) as qualified,
                count(*) filter (where {$stage['interested']}) as interested,
                count(*) filter (where {$stage['visit']}) as visits,
                count(*) filter (where {$stage['reservation']}) as reservations,
                count(*) filter (where {$stage['won']}) as won,
                coalesce(sum(leads.deal_value_minor) filter (where {$stage['won']}), 0) as revenue_minor")
            ->groupBy('channel')->orderByDesc('leads')->get();

        return $rows->map(fn ($r) => [
            'key' => (string) $r->channel, 'leads' => (int) $r->leads, 'qualified' => (int) $r->qualified, 'interested' => (int) $r->interested,
            'visits' => (int) $r->visits, 'reservations' => (int) $r->reservations, 'won' => (int) $r->won,
            'visit_rate' => $r->leads > 0 ? round($r->visits / $r->leads * 100, 1) : null,
        ] + ($this->ctx->earnings ? ['revenue' => round(((int) $r->revenue_minor) / 100, 2)] : []));
    }

    // ---------------------------------------------------------------- campaigns (§17)

    /**
     * @param  array<string,mixed>  $revenue
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function campaigns(array $revenue): array
    {
        $rows = collect($revenue['campaigns'] ?? []);
        $money = $this->ctx->money;
        $earnings = $this->ctx->earnings;

        return [
            'total' => $rows->count(),
            'rows' => $rows->take(50)->map(function (array $r) use ($money, $earnings) {
                $clicks = $r['clicks'] ?? null;

                return array_filter([
                    'name' => $r['name'], 'platform' => $r['platform'], 'status' => $r['status'] ?? null,
                    'project' => $r['project']['name'] ?? null,
                    'impressions' => $r['impressions'] ?? null, 'clicks' => $clicks, 'ctr' => $r['ctr'] ?? null,
                    'leads' => $r['leads'], 'qualified' => $r['qualified'], 'interested' => $r['interested'],
                    'visits' => $r['visits'], 'reservations' => $r['reservations'], 'won' => $r['won'],
                    'spend' => $money ? ($r['spend'] ?? null) : false,
                    'cpc' => $money && ($r['spend'] ?? null) !== null && $clicks > 0 ? round($r['spend'] / $clicks, 2) : false,
                    'cpl' => $money ? ($r['cpl'] ?? null) : false,
                    'cac' => $money ? ($r['cac'] ?? null) : false,
                    'roas' => $money && $earnings ? ($r['roas'] ?? null) : false,
                    'revenue' => $earnings ? ($r['revenue'] ?? null) : false,
                ], fn ($v) => $v !== false);
            })->values()->all(),
        ];
    }

    // ---------------------------------------------------------------- sources (§24)

    /** @return array{rows:list<array<string,mixed>>} only sources with leads in the period */
    public function sources(array $window): array
    {
        return ['rows' => $this->byChannel($window)->values()->all()];
    }

    // ---------------------------------------------------------------- sales (§18)

    /** @return array<string,mixed> */
    public function sales(array $window): array
    {
        $stage = LeadFunnel::stages();
        $sla = (int) Thresholds::workspace('sla_minutes');
        $r = $this->ctx->leads($window)->selectRaw("count(*) as received,
                count(*) filter (where leads.assigned_to is not null) as assigned,
                count(*) filter (where leads.assigned_to is null and leads.status not in ('won','lost')) as unassigned,
                count(*) filter (where leads.first_contact_at is not null) as contacted,
                count(*) filter (where leads.first_contact_at is null and leads.status not in ('won','lost')) as not_contacted,
                count(*) filter (where {$stage['qualified']}) as qualified,
                count(*) filter (where {$stage['interested']}) as interested,
                count(*) filter (where {$stage['visit']}) as visits,
                count(*) filter (where {$stage['reservation']}) as reservations,
                count(*) filter (where leads.status = 'won') as won,
                count(*) filter (where leads.status = 'lost') as lost,
                count(*) filter (where leads.assigned_at is not null and ((leads.first_contact_at is null and leads.assigned_at + make_interval(mins => ?) < now()) or leads.first_contact_at > leads.assigned_at + make_interval(mins => ?))) as sla_breaches,
                avg(extract(epoch from (leads.first_contact_at - leads.assigned_at)) / 60) filter (where leads.first_contact_at >= leads.assigned_at) as response_minutes,
                coalesce(sum(leads.deal_value_minor) filter (where leads.status = 'won'), 0) as revenue_minor", [$sla, $sla])->first();

        $leadIds = fn () => $this->ctx->leads($window)->select('leads.id');
        $followUps = FollowUp::query()->whereIn('lead_id', $leadIds())->where('status', '!=', 'cancelled')
            ->selectRaw("count(*) as planned, count(*) filter (where status = 'completed') as done,
                count(*) filter (where status = 'pending' and due_at < now()) as overdue")->first();
        $appointments = Appointment::query()->whereIn('lead_id', $leadIds())->count();

        $received = (int) $r->received;

        return [
            'received' => $received,
            'assigned' => (int) $r->assigned,
            'unassigned' => (int) $r->unassigned,
            'contacted' => (int) $r->contacted,
            'not_contacted' => (int) $r->not_contacted,
            'qualified' => (int) $r->qualified,
            'interested' => (int) $r->interested,
            'follow_ups' => (int) $followUps->planned,
            'follow_ups_done' => (int) $followUps->done,
            'overdue_follow_ups' => (int) $followUps->overdue,
            'appointments' => $appointments,
            'visits' => (int) $r->visits,
            'reservations' => (int) $r->reservations,
            'won' => (int) $r->won,
            'lost' => (int) $r->lost,
            'avg_first_response_minutes' => $r->response_minutes !== null ? (int) round((float) $r->response_minutes) : null,
            'sla_breaches' => (int) $r->sla_breaches,
            'sla_target_minutes' => $sla,
        ] + ($this->ctx->earnings ? ['revenue' => round(((int) $r->revenue_minor) / 100, 2)] : []);
    }

    // ---------------------------------------------------------------- team (§19)

    /**
     * Per salesperson. Names only — never a contact detail. Not for a public report unless the owner switched it on.
     *
     * @return list<array<string,mixed>>
     */
    public function team(array $window): array
    {
        $stage = LeadFunnel::stages();
        $sla = (int) Thresholds::workspace('sla_minutes');
        $rows = $this->ctx->leads($window)->whereNotNull('leads.assigned_to')->selectRaw("leads.assigned_to, count(*) as leads,
                count(*) filter (where leads.first_contact_at is not null) as contacted,
                avg(extract(epoch from (leads.first_contact_at - leads.assigned_at)) / 60) filter (where leads.first_contact_at >= leads.assigned_at and leads.assigned_at is not null) as response_minutes,
                count(*) filter (where {$stage['qualified']}) as qualified,
                count(*) filter (where {$stage['interested']}) as interested,
                count(*) filter (where {$stage['visit']}) as visits,
                count(*) filter (where {$stage['reservation']}) as reservations,
                count(*) filter (where leads.status = 'won') as won,
                coalesce(sum(leads.deal_value_minor) filter (where leads.status = 'won'), 0) as revenue_minor")
            ->groupBy('leads.assigned_to')->orderByDesc('won')->orderByDesc('leads')->limit(25)->get();

        $names = User::query()->whereIn('id', $rows->pluck('assigned_to'))->pluck('name', 'id');
        $followUps = FollowUp::query()->whereIn('assigned_to', $rows->pluck('assigned_to'))->whereBetween('due_at', [$window['from'], min($window['to'], now())])->where('status', '!=', 'cancelled')
            ->selectRaw("assigned_to, count(*) as due, count(*) filter (where status = 'completed') as done")->groupBy('assigned_to')->get()->keyBy('assigned_to');

        return $rows->map(function ($r) use ($names, $followUps) {
            $f = $followUps[$r->assigned_to] ?? null;

            return [
                'name' => $names[$r->assigned_to] ?? '—',
                'leads' => (int) $r->leads,
                'contact_rate' => $r->leads > 0 ? round($r->contacted / $r->leads * 100, 1) : null,
                'response_minutes' => $r->response_minutes !== null ? (int) round((float) $r->response_minutes) : null,
                'follow_up_completion' => $f && $f->due > 0 ? round($f->done / $f->due * 100, 1) : null,
                'qualified' => (int) $r->qualified, 'interested' => (int) $r->interested, 'visits' => (int) $r->visits,
                'reservations' => (int) $r->reservations, 'won' => (int) $r->won,
                'conversion_rate' => $r->leads > 0 ? round($r->won / $r->leads * 100, 1) : null,
            ] + ($this->ctx->earnings ? ['revenue' => round(((int) $r->revenue_minor) / 100, 2)] : []);
        })->values()->all();
    }

    // ---------------------------------------------------------------- lead quality (§20)

    /**
     * Only the attributes leads really carry: a bucket with no data is left out, a lead with the attribute blank is not
     * counted as anything.
     *
     * @return array<string,mixed>
     */
    public function leadQuality(array $window): array
    {
        $stage = LeadFunnel::stages();
        $leads = fn () => $this->ctx->leads($window);
        $total = (int) $leads()->count();

        $levels = $leads()->whereNotNull('leads.interest_level')->selectRaw('leads.interest_level as k, count(*) as n')->groupBy('k')->pluck('n', 'k');
        $bucket = fn (array $of) => (int) collect($of)->sum(fn ($k) => (int) ($levels[$k] ?? 0));
        $group = fn (string $column) => $leads()->whereNotNull("leads.{$column}")->where("leads.{$column}", '!=', '')
            ->selectRaw("leads.{$column} as k, count(*) as n")->groupBy('k')->orderByDesc('n')->limit(8)->get()->map(fn ($r) => ['key' => (string) $r->k, 'count' => (int) $r->n])->all();

        $row = $leads()->selectRaw("count(*) filter (where {$stage['qualified']}) as qualified, count(*) filter (where leads.status = 'lost') as lost")->first();
        $segments = $leads()->whereNotNull('leads.budget_max')->selectRaw("case when leads.budget_max < 500000 then 'under_500k' when leads.budget_max < 1000000 then '500k_1m' when leads.budget_max < 2000000 then '1m_2m' else 'over_2m' end as k, count(*) as n")->groupBy('k')->get()->map(fn ($r) => ['key' => (string) $r->k, 'count' => (int) $r->n])->all();
        $projectInterest = $leads()->whereNotNull('leads.project_id')->selectRaw('leads.project_id as k, count(*) as n')->groupBy('k')->orderByDesc('n')->limit(8)->get();
        $projects = Project::query()->whereIn('id', $projectInterest->pluck('k'))->pluck('name', 'id');

        return [
            'total' => $total,
            'interest' => $levels->isEmpty() ? null : ['high' => $bucket(['hot', 'visit_ready', 'reservation_ready']), 'medium' => $bucket(['interested', 'warm']), 'low' => $bucket(['cold'])],
            'qualified' => (int) $row->qualified,
            'unqualified' => max(0, $total - (int) $row->qualified - (int) $row->lost),
            'lost' => (int) $row->lost,
            'budget' => $segments,
            'purchase_timeline' => $group('purchase_timeline'),
            'financing' => $group('payment_method'),
            'unit_preference' => $group('preferred_property_type'),
            'project_interest' => $projectInterest->map(fn ($r) => ['name' => $projects[$r->k] ?? '—', 'count' => (int) $r->n])->all(),
        ];
    }

    // ---------------------------------------------------------------- loss analysis (§21)

    /** @return array<string,mixed> stored reasons only */
    public function loss(array $window): array
    {
        $stage = LeadFunnel::stages();
        $total = (int) $this->ctx->leads($window)->count();
        $lost = fn () => $this->ctx->leads($window)->where('leads.status', 'lost');
        $count = (int) $lost()->count();

        $reasons = $lost()->leftJoin('lost_reasons', 'lost_reasons.id', '=', 'leads.lost_reason_id')
            ->whereNotNull('lost_reasons.label')->selectRaw('lost_reasons.label as reason, count(*) as n')->groupBy('reason')->orderByDesc('n')->limit(6)->get();

        // The furthest stage the opportunity reached before it was lost.
        $at = $lost()->selectRaw("count(*) filter (where {$stage['reservation']}) as reservation,
            count(*) filter (where {$stage['visit']} and not {$stage['reservation']}) as visit,
            count(*) filter (where {$stage['interested']} and not {$stage['visit']}) as interested,
            count(*) filter (where not {$stage['interested']}) as early")->first();

        return [
            'lost' => $count,
            'rate' => $total > 0 ? round($count / $total * 100, 1) : null,
            'reasons' => $reasons->map(fn ($r) => ['label' => $r->reason, 'count' => (int) $r->n])->all(),
            'stage' => ['early' => (int) $at->early, 'interested' => (int) $at->interested, 'visit' => (int) $at->visit, 'reservation' => (int) $at->reservation],
        ];
    }

    // ---------------------------------------------------------------- projects (§25) and units (§26)

    /** @return list<array<string,mixed>> one row per project the report covers (or all, for a portfolio) */
    public function projects(array $window, array $revenue): array
    {
        $stage = LeadFunnel::stages();
        $rows = $this->ctx->leads($window)->whereNotNull('leads.project_id')->selectRaw('leads.project_id, count(*) as leads,
                count(distinct '.self::channelSql().") as sources,
                count(*) filter (where {$stage['qualified']}) as qualified,
                count(*) filter (where {$stage['interested']}) as interested,
                count(*) filter (where {$stage['visit']}) as visits,
                count(*) filter (where {$stage['reservation']}) as reservations,
                count(*) filter (where leads.status = 'won') as won,
                coalesce(sum(leads.deal_value_minor) filter (where leads.status = 'won'), 0) as revenue_minor")
            ->groupBy('leads.project_id')->orderByDesc('leads')->limit(30)->get();

        $names = Project::query()->whereIn('id', $rows->pluck('project_id'))->pluck('name', 'id');
        $spend = collect($revenue['campaigns'] ?? [])->groupBy(fn ($c) => $c['project']['name'] ?? null)->map(fn ($g) => $g->filter(fn ($c) => $c['spend'] !== null)->isEmpty() ? null : round((float) $g->sum('spend'), 2));
        $units = Property::query()->whereIn('project_id', $rows->pluck('project_id'))->selectRaw('project_id, count(*) as total, count(*) filter (where status = ?) as available, count(*) filter (where status = ?) as reserved, count(*) filter (where status in (?, ?)) as sold', ['available', 'reserved', 'sold', 'rented'])->groupBy('project_id')->get()->keyBy('project_id');

        return $rows->map(function ($r) use ($names, $spend, $units) {
            $u = $units[$r->project_id] ?? null;
            $name = $names[$r->project_id] ?? '—';

            return [
                'name' => $name, 'leads' => (int) $r->leads, 'sources' => (int) $r->sources,
                'qualified' => (int) $r->qualified, 'interested' => (int) $r->interested, 'visits' => (int) $r->visits,
                'reservations' => (int) $r->reservations, 'won' => (int) $r->won,
                'conversion_rate' => $r->leads > 0 ? round($r->won / $r->leads * 100, 1) : null,
                'units' => $u ? ['total' => (int) $u->total, 'available' => (int) $u->available, 'reserved' => (int) $u->reserved, 'sold' => (int) $u->sold] : null,
            ] + ($this->ctx->money ? ['spend' => $spend[$name] ?? null] : [])
              + ($this->ctx->earnings ? ['revenue' => round(((int) $r->revenue_minor) / 100, 2)] : []);
        })->values()->all();
    }

    /**
     * Units with real activity. A price appears only when the report owner allowed it.
     *
     * @return array{available:bool,rows:list<array<string,mixed>>}
     */
    public function units(array $window): array
    {
        $projects = $this->ctx->projectIds;
        $properties = Property::query()->when($projects !== [], fn ($q) => $q->whereIn('project_id', $projects))->get(['id', 'code', 'title', 'status', 'price_minor']);

        if ($properties->isEmpty()) {
            return ['available' => false, 'rows' => []];
        }

        $stage = LeadFunnel::stages();
        $interest = $this->ctx->leads($window)->whereNotNull('leads.interested_property_id')->selectRaw("leads.interested_property_id as pid, count(*) as interested,
                count(*) filter (where {$stage['visit']}) as visits,
                count(*) filter (where {$stage['reservation']}) as reservations,
                count(*) filter (where leads.status = 'won') as sold,
                coalesce(sum(leads.deal_value_minor) filter (where leads.status = 'won'), 0) as revenue_minor")->groupBy('pid')->get()->keyBy('pid');
        $showPrice = (bool) ($this->ctx->share->options['show_unit_prices'] ?? false);

        $rows = $properties->filter(fn ($p) => isset($interest[$p->id]))->map(function ($p) use ($interest, $showPrice) {
            $i = $interest[$p->id];

            return [
                'unit' => $p->code, 'interested' => (int) $i->interested, 'visits' => (int) $i->visits, 'reservations' => (int) $i->reservations, 'sold' => (int) $i->sold,
                'availability' => $p->status,
            ] + ($this->ctx->earnings ? ['revenue' => round(((int) $i->revenue_minor) / 100, 2)] : [])
              + ($showPrice && $p->price_minor !== null ? ['price' => round($p->price_minor / 100, 2)] : []);
        })->sortByDesc('interested')->take(30)->values()->all();

        return ['available' => $rows !== [], 'rows' => $rows];
    }

    // ---------------------------------------------------------------- tracking hub (§23)

    /** @return array<string,mixed> Traffic → Interest → Lead → Sales → Revenue; each figure is null if it was never measured */
    public function trackingHub(array $window): array
    {
        $stage = LeadFunnel::stages();
        $events = TrackingEvent::query()->whereBetween('occurred_at', [$window['from'], $window['to']])
            ->when($this->ctx->projectIds !== [], fn ($q) => $q->whereIn('project_id', $this->ctx->projectIds))
            ->when($this->ctx->platforms !== [], fn ($q) => $q->whereIn('platform', $this->ctx->platforms))
            ->when($this->ctx->campaignIds !== [], fn ($q) => $q->whereIn('campaign_id', $this->ctx->campaignIds))
            ->selectRaw("count(*) filter (where event_type = 'click' or meta->>'via' = 'link') as link_views,
                count(*) filter (where event_type = 'visit') as landing_views,
                count(*) filter (where event_type in ('form_start','form_step')) as form_views,
                count(*) filter (where event_type = 'form_start') as form_starts,
                count(*) filter (where event_type = 'form_submit') as form_submissions")->first();

        $leads = $this->ctx->leads($window)->whereNotNull('leads.first_touch_link_id')->selectRaw("count(*) as leads,
                count(*) filter (where {$stage['qualified']}) as qualified, count(*) filter (where {$stage['interested']}) as interested,
                count(*) filter (where {$stage['visit']}) as visits, count(*) filter (where {$stage['reservation']}) as reservations,
                coalesce(sum(leads.deal_value_minor) filter (where leads.status = 'won'), 0) as revenue_minor")->first();

        $measured = (int) $events->link_views + (int) $events->landing_views + (int) $events->form_starts + (int) $events->form_submissions;

        return [
            'available' => $measured > 0 || (int) $leads->leads > 0,
            'link_views' => (int) $events->link_views, 'landing_views' => (int) $events->landing_views,
            'form_views' => (int) $events->form_views, 'form_starts' => (int) $events->form_starts, 'form_submissions' => (int) $events->form_submissions,
            'leads' => (int) $leads->leads, 'qualified' => (int) $leads->qualified, 'interested' => (int) $leads->interested,
            'visits' => (int) $leads->visits, 'reservations' => (int) $leads->reservations,
        ] + ($this->ctx->earnings ? ['revenue' => round(((int) $leads->revenue_minor) / 100, 2)] : []);
    }

    // ---------------------------------------------------------------- revenue

    /** @return array<string,mixed> */
    public function revenue(array $window, array $revenue): array
    {
        $won = fn () => $this->ctx->leads($window)->where('leads.status', 'won');
        $row = $won()->selectRaw('count(*) as deals, coalesce(sum(leads.deal_value_minor), 0) as total, coalesce(avg(leads.deal_value_minor), 0) as average')->first();
        $deals = (int) $row->deals;
        $byChannel = $won()->selectRaw($this->channelSql().' as channel, count(*) as deals, sum(leads.deal_value_minor) as total')->groupBy('channel')->orderByDesc('total')->get();

        return [
            'deals' => $deals,
            'total' => $deals > 0 ? round(((int) $row->total) / 100, 2) : null,
            'average_deal' => $deals > 0 ? round(((float) $row->average) / 100, 2) : null,
            'by_source' => $byChannel->map(fn ($r) => ['key' => (string) $r->channel, 'deals' => (int) $r->deals, 'revenue' => round(((int) $r->total) / 100, 2)])->values()->all(),
            'by_project' => collect($this->projects($window, $revenue))->filter(fn ($p) => ($p['revenue'] ?? 0) > 0)->map(fn ($p) => ['name' => $p['name'], 'revenue' => $p['revenue']])->values()->all(),
        ];
    }

    // ---------------------------------------------------------------- attention (§32)

    /**
     * What needs a person's eye. Counts and flags only; a link into the product is for the team's copy, never the client's.
     *
     * @param  array<string,mixed>  $figures  current figures (kpis) for the comparisons
     * @param  array<string,mixed>  $before  the same, a period earlier (or empty)
     * @return list<array<string,mixed>>
     */
    public function attention(array $window, array $sales, array $figures, array $before, bool $team): array
    {
        $items = [];
        $add = function (string $key, string $severity, array $values = []) use (&$items, $team) {
            $items[] = ['key' => $key, 'severity' => $severity, 'values' => $values] + ($team ? ['link' => $this->linkFor($key)] : []);
        };

        $sla = (int) Thresholds::workspace('sla_minutes');
        $waiting = (int) $this->ctx->narrow(Lead::query()->reorder())->whereNull('leads.first_contact_at')->whereIn('leads.status', ['new'])->where('leads.created_at', '<', now()->subMinutes($sla))->count();
        $waiting > 0 && $add('leads_not_contacted', 'warning', ['n' => $waiting]);
        $sales['sla_breaches'] > 0 && $add('sla_breaches', 'critical', ['n' => $sales['sla_breaches']]);
        $sales['unassigned'] > 0 && $add('unassigned_leads', 'warning', ['n' => $sales['unassigned']]);
        $sales['overdue_follow_ups'] > 0 && $add('overdue_follow_ups', 'warning', ['n' => $sales['overdue_follow_ups']]);

        $cpl = $figures['cpl'] ?? null;
        $cplBefore = $before['cpl'] ?? null;

        if ($this->ctx->money && $cpl !== null && $cplBefore !== null && $cplBefore > 0 && $cpl >= $cplBefore * 1.3) {
            $add('high_cpl', 'warning', ['current' => $cpl, 'previous' => $cplBefore, 'change' => round(($cpl - $cplBefore) / $cplBefore * 100, 1)]);
        }

        $rate = ($figures['leads'] ?? 0) > 0 ? ($figures['interested'] ?? 0) / $figures['leads'] : null;
        $rateBefore = ($before['leads'] ?? 0) > 0 ? ($before['interested'] ?? 0) / $before['leads'] : null;

        if ($rate !== null && $rateBefore !== null && $rateBefore > 0 && $rate <= $rateBefore * 0.8) {
            $add('conversion_decline', 'warning', ['current' => round($rate * 100, 1), 'previous' => round($rateBefore * 100, 1)]);
        }

        $failedExports = (int) ConversionExport::query()->whereBetween('created_at', [$window['from'], $window['to']])->where('status', ConversionExport::FAILED)->count();
        $failedExports > 0 && $add('tracking_issues', 'warning', ['n' => $failedExports]);

        $failedRuns = (int) AutomationActionLog::query()->whereBetween('happened_at', [$window['from'], $window['to']])->where('result', AutomationActionLog::FAILED)->count();
        $failedRuns > 0 && $add('automation_failures', 'warning', ['n' => $failedRuns]);

        if ($this->ctx->money) {
            $silent = $this->silentSpend($window);
            $silent > 0 && $add('spend_without_conversion', 'warning', ['n' => $silent]);
        }

        return $items;
    }

    /** Campaigns that spent in the period and produced no qualified lead. */
    private function silentSpend(array $window): int
    {
        $rows = (new CampaignMetrics(null, $window['from'], $window['to']))->rows(['project_ids' => $this->ctx->projectIds] + $this->ctx->campaignFilter());

        return collect($rows)->filter(fn ($r) => ($r['spend'] ?? 0) > 0 && ($r['qualified'] ?? 0) === 0)->count();
    }

    private function linkFor(string $key): string
    {
        return match ($key) {
            'leads_not_contacted', 'sla_breaches' => '/leads?view=waiting',
            'unassigned_leads' => '/leads?view=unassigned',
            'overdue_follow_ups' => '/follow-ups?view=overdue',
            'high_cpl', 'conversion_decline', 'spend_without_conversion' => '/campaigns',
            'tracking_issues' => '/tracking',
            'automation_failures' => '/automations',
            default => '/',
        };
    }

    // ---------------------------------------------------------------- findings (§31)

    /**
     * Statements that follow from the numbers and from nothing else. Each is a key and the values it was drawn from; the
     * wording is the app's. Nothing is said about a comparison that has no data on one side.
     *
     * @param  array<string,mixed>  $marketing
     * @param  list<array<string,mixed>>  $sources
     * @param  array<string,mixed>  $sales
     * @param  array<string,mixed>  $figures
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $salesBefore
     * @return list<array<string,mixed>>
     */
    public function findings(array $marketing, array $sources, array $campaigns, array $sales, array $salesBefore, array $figures, array $before): array
    {
        $out = [];
        $add = function (string $key, string $severity, array $values) use (&$out) {
            $out[] = ['key' => $key, 'severity' => $severity, 'values' => $values];
        };

        $withCpl = collect($marketing['mohtam_attributed'] ?? [])->filter(fn ($r) => ($r['cpl'] ?? null) !== null && $r['leads'] >= 5)->sortBy('cpl');
        if ($withCpl->count() >= 2) {
            $best = $withCpl->first();
            $add('best_platform_cpl', 'good', ['platform' => $best['key'], 'cpl' => $best['cpl']]);
        }

        $bySource = collect($sources)->filter(fn ($r) => $r['leads'] >= 5 && $r['visit_rate'] !== null);
        if ($bySource->count() >= 2 && ($top = $bySource->sortByDesc('visit_rate')->first())['visit_rate'] > 0) {
            $add('best_source_visit_rate', 'good', ['source' => $top['key'], 'rate' => $top['visit_rate']]);
        }

        $byRevenue = collect($sources)->filter(fn ($r) => ($r['revenue'] ?? 0) > 0)->sortByDesc('revenue');
        if ($byRevenue->isNotEmpty()) {
            $add('best_source_revenue', 'good', ['source' => $byRevenue->first()['key'], 'revenue' => $byRevenue->first()['revenue']]);
        }

        $rate = ($figures['leads'] ?? 0) > 0 ? $figures['interested'] / $figures['leads'] * 100 : null;
        $rateBefore = ($before['leads'] ?? 0) > 0 ? $before['interested'] / $before['leads'] * 100 : null;
        if ($rate !== null && $rateBefore !== null && $rateBefore > 0 && abs($rate - $rateBefore) / $rateBefore >= 0.15) {
            $add($rate > $rateBefore ? 'interest_rate_up' : 'interest_rate_down', $rate > $rateBefore ? 'good' : 'warning', ['current' => round($rate, 1), 'previous' => round($rateBefore, 1)]);
        }

        $speed = $sales['avg_first_response_minutes'] ?? null;
        $speedBefore = $salesBefore['avg_first_response_minutes'] ?? null;
        if ($speed !== null && $speedBefore !== null && $speedBefore > 0 && $speed >= $speedBefore * 1.3) {
            $add('response_slower', 'warning', ['current' => $speed, 'previous' => $speedBefore]);
        }

        $rows = collect($campaigns['rows'] ?? []);
        $overall = $rows->sum('leads') > 0 ? $rows->sum('qualified') / $rows->sum('leads') : null;
        $weak = $overall === null ? null : $rows->first(fn ($r) => $r['leads'] >= 10 && $overall * 0.5 >= $r['qualified'] / $r['leads']);
        $weak && $add('campaign_volume_low_quality', 'warning', ['campaign' => $weak['name'], 'leads' => $weak['leads'], 'qualified' => $weak['qualified']]);

        $avgVisit = collect($sources)->sum('leads') > 0 ? collect($sources)->sum('visits') / collect($sources)->sum('leads') * 100 : null;
        $small = $avgVisit === null ? null : collect($sources)->first(fn ($r) => $r['leads'] >= 3 && $r['leads'] <= 10 && ($r['visit_rate'] ?? 0) >= max(20, $avgVisit * 1.5));
        $small && $add('small_source_high_conversion', 'info', ['source' => $small['key'], 'leads' => $small['leads'], 'rate' => $small['visit_rate']]);

        return $out;
    }

    // ---------------------------------------------------------------- canonical funnel (§2–§4)

    /** @return array<string,mixed> */
    public function funnel(array $window, bool $withRevenue): array
    {
        return app(RealEstateFunnel::class)->build($this->ctx->funnelFilters($window), null, $withRevenue);
    }
}
