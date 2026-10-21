<?php

namespace App\Domain\Analytics;

use App\Domain\Leads\InterestEngine;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;

/**
 * The real-estate funnel: Lead → Qualified → Interested → Visit → Reservation → Won.
 *
 * A lead counts at every step up to the furthest one it reached — a won lead was, by definition,
 * qualified, interested, saw the unit and held it — so each step is never larger than the one
 * before it and the drop between two steps is the number that was lost there. One SQL pass, so a
 * workspace with a hundred thousand leads costs the same as one with a hundred.
 *
 * A *visit* is one that happened (completed); booked ones are reported beside it, not instead.
 */
class LeadFunnel
{
    public const STEPS = ['leads', 'qualified', 'interested', 'visit', 'reservation', 'won'];

    /**
     * @param  Builder<Lead>  $leads  Already scoped (period, project, campaign, who may see it).
     * @return array{steps:array<string,int>, booked:int}
     */
    public static function counts(Builder $leads): array
    {
        $interestedLevels = "'".implode("','", InterestEngine::INTERESTED)."'";

        // Each step is "reached this one, or any later one".
        $won = "leads.status = 'won'";
        $reservation = "(exists (select 1 from reservations r where r.lead_id = leads.id and r.status in ('pending','confirmed','converted')) or {$won})";
        $visit = "(exists (select 1 from appointments av where av.lead_id = leads.id and av.deleted_at is null and av.status = 'completed') or {$reservation})";
        $interested = "(leads.interest_level in ({$interestedLevels}) or {$visit})";
        $qualified = "(leads.qualification_status = 'qualified' or leads.status in ('qualified','appointment','negotiation','won') or {$interested})";

        $row = (clone $leads)->reorder()->selectRaw("
            count(*) as leads,
            count(*) filter (where {$qualified}) as qualified,
            count(*) filter (where {$interested}) as interested,
            count(*) filter (where {$visit}) as visit,
            count(*) filter (where {$reservation}) as reservation,
            count(*) filter (where {$won}) as won,
            count(*) filter (where exists (select 1 from appointments ab where ab.lead_id = leads.id and ab.deleted_at is null and ab.status in ('confirmed','rescheduled','scheduled','completed'))) as booked
        ")->first();

        return [
            'steps' => collect(self::STEPS)->mapWithKeys(fn (string $step) => [$step => (int) $row->{$step}])->all(),
            'booked' => (int) $row->booked,
        ];
    }
}
