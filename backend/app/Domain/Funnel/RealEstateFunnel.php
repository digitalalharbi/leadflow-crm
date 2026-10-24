<?php

namespace App\Domain\Funnel;

use App\Domain\Analytics\LeadFunnel;
use App\Domain\Leads\InterestEngine;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\MetricSnapshot;
use App\Models\TrackingEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one real-estate funnel of the product:
 *
 *   وعي → اهتمام → تفاعل → حجز معاينة → قرار شراء → إحالة
 *
 * Every screen, report, summary and export that draws a funnel draws this one. The operational stages (qualified,
 * interested, appointment, visit, reservation, negotiation, won, lost) are not removed: they are the `sub_stages` of the
 * stage they belong to, so a manager can still see them and nobody has a second funnel to reconcile.
 *
 * What each stage means, from what Mohtam actually records:
 *
 *   awareness   traffic. What the ad platforms report (impressions) when an account is connected, otherwise what Mohtam
 *               itself measured (visitors reaching a tracking link, landing page or form). The two are never added
 *               together or passed off as each other: `basis` says which one the number is.
 *   interest    a lead exists: a form, a message, a call, an import, a hand entry.
 *   engagement  somebody on the team reached them or they answered — contacted, replied, qualified, interested,
 *               or anything beyond. A lead that went further is counted here too (it was engaged on the way).
 *   booking     a viewing is booked, scheduled or done — or the lead went past it (reservation, negotiation, sale).
 *   decision    a reservation, a negotiation or a sale. Revenue sits here.
 *   referral    leads that came through a partner or a broker, or a source recorded as a referral. It is not a subset of
 *               the stage before it (a referred lead enters at interest), so it has no conversion or drop-off.
 *
 * Nothing is invented: a stage whose data does not exist says so (`available: false`) instead of showing a zero.
 */
class RealEstateFunnel
{
    public const STAGES = ['awareness', 'interest', 'engagement', 'booking', 'decision', 'referral'];

    public const LABELS = [
        'awareness' => 'وعي',
        'interest' => 'اهتمام',
        'engagement' => 'تفاعل',
        'booking' => 'حجز معاينة',
        'decision' => 'قرار شراء',
        'referral' => 'إحالة',
    ];

    /** The stages that are the lead's own journey, each contained in the one before. */
    private const CHAIN = ['interest', 'engagement', 'booking', 'decision'];

    /**
     * @return array<string, mixed>
     */
    public function build(FunnelFilters $filters, ?User $viewer = null, bool $withRevenue = true): array
    {
        $now = $this->cohort($filters, $viewer);
        $before = $this->cohort($filters->previous(), $viewer);
        $traffic = $this->awareness($filters, $viewer);
        $trafficBefore = $this->awareness($filters->previous(), $viewer);

        $counts = [
            'awareness' => $traffic['count'],
            'interest' => $now['interest'],
            'engagement' => $now['engagement'],
            'booking' => $now['booking'],
            'decision' => $now['decision'],
            'referral' => $now['referral'],
        ];
        $previous = [
            'awareness' => $trafficBefore['count'],
            'interest' => $before['interest'],
            'engagement' => $before['engagement'],
            'booking' => $before['booking'],
            'decision' => $before['decision'],
            'referral' => $before['referral'],
        ];

        $stages = [];
        $upstream = null;

        foreach (self::STAGES as $key) {
            $available = $key === 'awareness' ? $traffic['available'] : true;
            $count = $available ? $counts[$key] : null;
            $rate = null;

            if ($key === 'interest' && $available && $traffic['available'] && ($traffic['tracked_leads'] ?? null) !== null && $traffic['count'] > 0) {
                // Awareness is not made of leads, so its conversion is measured on the leads that can be traced to it.
                $rate = round($traffic['tracked_leads'] / $traffic['count'] * 100, 2);
            } elseif (in_array($key, ['engagement', 'booking', 'decision'], true) && $upstream !== null && $upstream > 0) {
                $rate = round($counts[$key] / $upstream * 100, 1);
            }

            $stages[] = [
                'key' => $key,
                'label' => self::LABELS[$key],
                'available' => $available,
                'count' => $count,
                'rate' => $rate,
                'drop_off' => $rate === null ? null : round(max(0, 100 - $rate), 1),
                'previous' => $available && $previous[$key] !== null ? $previous[$key] : null,
                'change' => $available ? $this->change($counts[$key], $previous[$key]) : null,
                'sub_stages' => $this->subStages($key, $now, $withRevenue),
            ] + ($withRevenue && in_array($key, ['decision', 'referral'], true) ? ['revenue' => $this->money($key === 'decision' ? $now['revenue_minor'] : $now['referral_revenue_minor'])] : [])
              + ($key === 'awareness' ? ['basis' => $traffic['basis'], 'components' => $traffic['components'], 'reason' => $traffic['reason']] : [])
              + ($key === 'referral' ? ['subset_of_previous' => false] : []);

            if (in_array($key, self::CHAIN, true)) {
                $upstream = $counts[$key];
            }
        }

        return [
            'stages' => $stages,
            'period' => ['from' => $filters->from->toDateString(), 'to' => $filters->to->toDateString(), 'days' => $filters->days()],
            'previous_period' => ['from' => $filters->compareFrom->toDateString(), 'to' => $filters->compareTo->toDateString()],
            'trend' => $this->trend($filters, $viewer),
            'lost' => ['count' => $now['lost'], 'rate' => $now['interest'] > 0 ? round($now['lost'] / $now['interest'] * 100, 1) : null],
        ] + ($withRevenue ? ['revenue' => $this->money($now['revenue_minor'])] : []) + [
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * The conditions each lead stage stands on, as SQL on `leads`. One definition, used by every query below.
     *
     * @return array<string, string>
     */
    public static function conditions(): array
    {
        $base = LeadFunnel::stages();
        $interested = "(leads.interest_level in ('".implode("','", InterestEngine::INTERESTED)."'))";

        $decision = "(leads.status in ('negotiation','won') or exists (select 1 from reservations rd where rd.lead_id = leads.id and rd.status in ('pending','confirmed','converted')))";
        $booking = "(leads.status in ('appointment') or exists (select 1 from appointments ab where ab.lead_id = leads.id and ab.deleted_at is null and ab.status in ('confirmed','rescheduled','scheduled','completed')) or {$decision})";
        $engagement = "(leads.first_contact_at is not null or leads.first_response_at is not null or leads.status in ('contacted','replied','qualified','appointment','negotiation','won') or {$base['qualified']} or {$interested} or {$booking})";
        $referral = "(leads.partner_id is not null or lower(coalesce(leads.source, '')) in ('referral','referred','broker','partner','إحالة'))";

        return [
            'engagement' => $engagement,
            'booking' => $booking,
            'decision' => $decision,
            'referral' => $referral,
            'qualified' => $base['qualified'],
            'interested' => $base['interested'],
            'visit' => $base['visit'],
            'reservation' => $base['reservation'],
            'won' => $base['won'],
        ];
    }

    /** @return Builder<Lead> the leads that arrived in the period, within the filters */
    public function leads(FunnelFilters $f, ?User $viewer): Builder
    {
        return Lead::query()->reorder()
            ->whereBetween('leads.created_at', [$f->from, $f->to])
            ->when($viewer !== null, fn ($q) => $q->visibleTo($viewer))
            ->when($f->projectIds !== [], fn ($q) => $q->whereIn('leads.project_id', $f->projectIds))
            ->when($f->partnerId, fn ($q, $id) => $q->where('leads.partner_id', $id))
            ->when($f->platforms !== [], fn ($q) => $q->whereIn('leads.platform', $f->leadPlatforms()))
            ->when($f->campaignIds !== [], fn ($q) => $q->whereIn('leads.campaign_ref_id', $f->campaignIds))
            ->when($f->sources !== [], fn ($q) => $q->whereIn('leads.source', $f->sources))
            ->when($f->assignedTo !== [], fn ($q) => $q->whereIn('leads.assigned_to', $f->assignedTo));
    }

    /** @return array<string, int> */
    private function cohort(FunnelFilters $f, ?User $viewer): array
    {
        $c = self::conditions();

        $row = $this->leads($f, $viewer)->selectRaw("
            count(*) as interest,
            count(*) filter (where {$c['engagement']}) as engagement,
            count(*) filter (where {$c['booking']}) as booking,
            count(*) filter (where {$c['decision']}) as decision,
            count(*) filter (where {$c['referral']}) as referral,
            count(*) filter (where leads.partner_id is not null) as partner_referrals,
            count(*) filter (where {$c['referral']} and leads.partner_id is null) as source_referrals,
            count(*) filter (where leads.first_contact_at is not null or leads.status in ('contacted','replied','qualified','appointment','negotiation','won')) as contacted,
            count(*) filter (where {$c['qualified']}) as qualified,
            count(*) filter (where {$c['interested']}) as interested,
            count(*) filter (where leads.status = 'negotiation') as negotiation,
            count(*) filter (where exists (select 1 from appointments av where av.lead_id = leads.id and av.deleted_at is null and av.status in ('confirmed','rescheduled','scheduled','completed'))) as appointments,
            count(*) filter (where {$c['visit']}) as visits,
            count(*) filter (where {$c['reservation']}) as reservations,
            count(*) filter (where {$c['won']}) as won,
            count(*) filter (where leads.status = 'lost') as lost,
            coalesce(sum(leads.deal_value_minor) filter (where leads.status = 'won'), 0) as revenue_minor,
            coalesce(sum(leads.deal_value_minor) filter (where leads.status = 'won' and {$c['referral']}), 0) as referral_revenue_minor
        ");

        return collect($row->first()?->getAttributes() ?? [])->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Traffic: from the ad platforms when they report it, otherwise from what Mohtam measured, otherwise nothing.
     *
     * @return array{available: bool, count: ?int, basis: ?string, reason: ?string, components: array<string, mixed>, tracked_leads: ?int}
     */
    private function awareness(FunnelFilters $f, ?User $viewer): array
    {
        if ($f->partnerId !== null) {
            return ['available' => false, 'count' => null, 'basis' => null, 'reason' => 'by_partner', 'components' => [], 'tracked_leads' => null];
        }

        if ($f->assignedTo !== []) {
            // Nobody works traffic: by rep, there is no awareness to speak of.
            return ['available' => false, 'count' => null, 'basis' => null, 'reason' => 'by_rep', 'components' => [], 'tracked_leads' => null];
        }

        $campaigns = Campaign::query()->select('campaigns.id')
            ->when($f->projectIds !== [], fn ($q) => $q->whereIn('campaigns.project_id', $f->projectIds))
            ->when($f->platforms !== [], fn ($q) => $q->whereIn('campaigns.platform', $f->platforms))
            ->when($f->campaignIds !== [], fn ($q) => $q->whereIn('campaigns.id', $f->campaignIds));

        $platform = MetricSnapshot::query()->whereIn('campaign_id', $campaigns)
            ->whereBetween('date', [$f->from->toDateString(), $f->to->toDateString()])
            ->selectRaw('coalesce(sum(impressions), 0) as impressions, coalesce(sum(clicks), 0) as clicks, count(*) as days')->first();

        $events = TrackingEvent::query()->whereBetween('occurred_at', [$f->from, $f->to])
            ->when($f->projectIds !== [], fn ($q) => $q->whereIn('project_id', $f->projectIds))
            ->when($f->platforms !== [], fn ($q) => $q->whereIn('platform', $f->platforms))
            ->when($f->campaignIds !== [], fn ($q) => $q->whereIn('campaign_id', $f->campaignIds))
            ->when($f->sources !== [], fn ($q) => $q->whereIn('utm_source', $f->sources))
            ->selectRaw("
                count(*) filter (where event_type = 'click' or meta->>'via' = 'link') as link_clicks,
                count(*) filter (where event_type = 'visit') as landing_views,
                count(*) filter (where event_type = 'form_start') as form_views,
                count(distinct visitor_id) as visitors
            ")->first();

        $impressions = (int) ($platform->impressions ?? 0);
        $adClicks = (int) ($platform->clicks ?? 0);
        $measured = max((int) ($events->visitors ?? 0), (int) ($events->link_clicks ?? 0));

        // Never mixed: Mohtam measured traffic and platform-reported traffic are kept in their own components.
        $components = [
            'platform_reported' => $impressions > 0 || $adClicks > 0 ? ['impressions' => $impressions, 'ad_clicks' => $adClicks] : null,
            'mohtam_measured' => $measured > 0 ? [
                'visitors' => (int) $events->visitors, 'link_clicks' => (int) $events->link_clicks,
                'landing_views' => (int) $events->landing_views, 'form_views' => (int) $events->form_views,
            ] : null,
        ];

        if ($impressions > 0) {
            $basis = 'platform';
            $count = $impressions;
            $tracked = $this->leads($f, $viewer)->whereNotNull('leads.campaign_ref_id')->count();
        } elseif ($measured > 0) {
            $basis = 'mohtam';
            $count = $measured;
            $tracked = $this->leads($f, $viewer)->whereNotNull('leads.first_touch_link_id')->count();
        } else {
            return ['available' => false, 'count' => null, 'basis' => null, 'reason' => 'no_traffic_data', 'components' => $components, 'tracked_leads' => null];
        }

        return ['available' => true, 'count' => $count, 'basis' => $basis, 'reason' => null, 'components' => $components, 'tracked_leads' => $tracked];
    }

    /** @param  array<string,int>  $now */
    private function subStages(string $stage, array $now, bool $withRevenue): array
    {
        return match ($stage) {
            'engagement' => [
                ['key' => 'contacted', 'count' => $now['contacted'] ?? null],
                ['key' => 'qualified', 'count' => $now['qualified']],
                ['key' => 'interested', 'count' => $now['interested']],
            ],
            'booking' => [
                ['key' => 'appointment_booked', 'count' => $now['appointments']],
                ['key' => 'visit_completed', 'count' => $now['visits']],
            ],
            'decision' => [
                ['key' => 'reservation', 'count' => $now['reservations']],
                ['key' => 'negotiation', 'count' => $now['negotiation']],
                ['key' => 'won', 'count' => $now['won']] + ($withRevenue ? ['revenue' => $this->money($now['revenue_minor'])] : []),
            ],
            'referral' => [
                ['key' => 'broker_referral', 'count' => $now['partner_referrals']],
                ['key' => 'source_referral', 'count' => $now['source_referrals']],
            ],
            default => [],
        };
    }

    /** Leads per day (or per week over a long period) at each of the lead's own stages, by the day they arrived. */
    private function trend(FunnelFilters $f, ?User $viewer): array
    {
        $c = self::conditions();
        $weekly = $f->days() > 62;
        $bucket = $weekly ? "date_trunc('week', leads.created_at)::date" : 'leads.created_at::date';

        $rows = $this->leads($f, $viewer)->selectRaw("
            {$bucket} as day,
            count(*) as interest,
            count(*) filter (where {$c['engagement']}) as engagement,
            count(*) filter (where {$c['booking']}) as booking,
            count(*) filter (where {$c['decision']}) as decision
        ")->groupBy('day')->orderBy('day')->get()->keyBy(fn ($r) => (string) $r->day);

        $points = [];
        $cursor = $weekly ? $f->from->startOfWeek() : $f->from;

        while ($cursor->lte($f->to)) {
            $key = $cursor->toDateString();
            $r = $rows->get($key);
            $points[] = ['date' => $key, 'interest' => (int) ($r->interest ?? 0), 'engagement' => (int) ($r->engagement ?? 0), 'booking' => (int) ($r->booking ?? 0), 'decision' => (int) ($r->decision ?? 0)];
            $cursor = $weekly ? $cursor->addWeek() : $cursor->addDay();
        }

        return ['bucket' => $weekly ? 'week' : 'day', 'points' => $points];
    }

    private function change(?int $now, ?int $before): ?float
    {
        return $before !== null && $before > 0 && $now !== null ? round(($now - $before) / $before * 100, 1) : null;
    }

    private function money(int $minor): float
    {
        return round($minor / 100, 2);
    }
}
