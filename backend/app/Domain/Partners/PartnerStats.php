<?php

namespace App\Domain\Partners;

use App\Domain\Analytics\LeadFunnel;
use App\Models\Lead;
use App\Models\Partner;
use App\Models\TrackingEvent;
use App\Models\TrackingLink;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * What each partner has brought, down the same funnel as everything else — and what it is owed.
 *
 * Credit follows the link that first touched the lead (`leads.partner_id`). The commission is the
 * revenue of the partner's won leads times the partner's percentage, and is null — not zero —
 * until a percentage has been agreed.
 */
class PartnerStats
{
    /**
     * @param  array<int,int>  $partnerIds
     * @return array<int,array<string,mixed>>
     */
    public function for(array $partnerIds, ?CarbonInterface $since = null): array
    {
        if ($partnerIds === []) {
            return [];
        }

        $stage = LeadFunnel::stages();

        $leads = Lead::query()->reorder()->whereIn('leads.partner_id', $partnerIds)
            ->when($since, fn ($q) => $q->where('leads.created_at', '>=', $since))
            ->selectRaw("leads.partner_id, count(*) as leads,
                count(*) filter (where {$stage['qualified']}) as qualified,
                count(*) filter (where {$stage['interested']}) as interested,
                count(*) filter (where {$stage['visit']}) as visits,
                count(*) filter (where {$stage['reservation']}) as reservations,
                count(*) filter (where {$stage['won']}) as won,
                coalesce(sum(leads.deal_value_minor) filter (where {$stage['won']}), 0) as revenue_minor,
                max(leads.created_at) as last_lead_at")
            ->groupBy('leads.partner_id')->get()->keyBy('partner_id');

        $links = TrackingLink::withTrashed()->whereIn('partner_id', $partnerIds)->get(['id', 'partner_id']);
        $clicks = TrackingEvent::query()->whereIn('tracking_link_id', $links->pluck('id'))->whereIn('event_type', ['click', 'whatsapp_click', 'call_click'])
            ->when($since, fn ($q) => $q->where('occurred_at', '>=', $since))
            ->selectRaw('tracking_link_id, count(*) as n')->groupBy('tracking_link_id')->pluck('n', 'tracking_link_id');
        $clicksByPartner = $links->groupBy('partner_id')->map(fn ($group) => (int) $group->sum(fn ($l) => (int) ($clicks[$l->id] ?? 0)));
        $linkCount = $links->groupBy('partner_id')->map->count();

        $percent = Partner::query()->whereIn('id', $partnerIds)->pluck('commission_percent', 'id');

        $out = [];

        foreach ($partnerIds as $id) {
            $r = $leads->get($id);
            $revenueMinor = (int) ($r->revenue_minor ?? 0);
            $pct = $percent[$id] ?? null;

            $out[$id] = [
                'links' => (int) ($linkCount[$id] ?? 0),
                'clicks' => (int) ($clicksByPartner[$id] ?? 0),
                'leads' => (int) ($r->leads ?? 0),
                'qualified' => (int) ($r->qualified ?? 0),
                'interested' => (int) ($r->interested ?? 0),
                'visits' => (int) ($r->visits ?? 0),
                'reservations' => (int) ($r->reservations ?? 0),
                'won' => (int) ($r->won ?? 0),
                'revenue' => round($revenueMinor / 100, 2),
                'commission' => $pct !== null ? round($revenueMinor / 100 * (float) $pct / 100, 2) : null,
                'last_lead_at' => $r?->last_lead_at ? Carbon::parse($r->last_lead_at)->toIso8601String() : null,
            ];
        }

        return $out;
    }
}
