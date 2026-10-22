<?php

namespace App\Domain\Tracking;

use App\Domain\Analytics\LeadFunnel;
use App\Models\Lead;
use App\Models\TrackingEvent;
use Illuminate\Support\Carbon;

/**
 * What each link has drawn and what became of it, in two grouped queries — never one per link.
 *
 * Clicks and visitors are what the link produced; leads, qualified, won and revenue are the leads
 * whose FIRST touch was this link (the lead's origin), so a link's "leads" and the attribution
 * report's first-touch row are the same number.
 */
class LinkStats
{
    /**
     * @param  array<int,int>  $linkIds
     * @return array<int,array<string,mixed>>
     */
    public function for(array $linkIds, ?Carbon $since = null): array
    {
        if ($linkIds === []) {
            return [];
        }

        $events = TrackingEvent::query()->whereIn('tracking_link_id', $linkIds)->when($since, fn ($q) => $q->where('occurred_at', '>=', $since))
            ->selectRaw("tracking_link_id,
                count(*) filter (where event_type in ('click','whatsapp_click','call_click')) as clicks,
                count(distinct visitor_id) as visitors,
                count(*) filter (where event_type = 'visit') as visits,
                count(*) filter (where event_type = 'form_submit') as forms,
                count(*) filter (where event_type = 'whatsapp_click') as whatsapp,
                count(*) filter (where event_type = 'call_click') as calls,
                max(occurred_at) as last_at")
            ->groupBy('tracking_link_id')->get()->keyBy('tracking_link_id');

        $stage = LeadFunnel::stages();

        $leads = Lead::query()->reorder()->whereIn('first_touch_link_id', $linkIds)->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->selectRaw("first_touch_link_id,
                count(*) as leads,
                count(*) filter (where qualification_status = 'qualified' or status in ('qualified','appointment','negotiation','won')) as qualified,
                count(*) filter (where {$stage['interested']}) as interested,
                count(*) filter (where {$stage['visit']}) as visits_done,
                count(*) filter (where {$stage['reservation']}) as reservations,
                count(*) filter (where status = 'won') as won,
                coalesce(sum(deal_value_minor) filter (where status = 'won'), 0) as revenue")
            ->groupBy('first_touch_link_id')->get()->keyBy('first_touch_link_id');

        $out = [];
        foreach ($linkIds as $id) {
            $e = $events->get($id);
            $l = $leads->get($id);
            $clicks = (int) ($e->clicks ?? 0);
            $out[$id] = [
                'clicks' => $clicks,
                'visitors' => (int) ($e->visitors ?? 0),
                'visits' => (int) ($e->visits ?? 0),
                'forms' => (int) ($e->forms ?? 0),
                'whatsapp_clicks' => (int) ($e->whatsapp ?? 0),
                'call_clicks' => (int) ($e->calls ?? 0),
                'leads' => (int) ($l->leads ?? 0),
                'qualified' => (int) ($l->qualified ?? 0),
                'interested' => (int) ($l->interested ?? 0),
                // Appointments that happened — not the site visits of `visits` above.
                'visits_done' => (int) ($l->visits_done ?? 0),
                'reservations' => (int) ($l->reservations ?? 0),
                'won' => (int) ($l->won ?? 0),
                'revenue' => round(((int) ($l->revenue ?? 0)) / 100, 2),
                'conversion' => $clicks > 0 ? round(((int) ($l->leads ?? 0)) / $clicks * 100, 1) : null,
                'last_event_at' => $e?->last_at ? Carbon::parse($e->last_at)->toIso8601String() : null,
            ];
        }

        return $out;
    }
}
