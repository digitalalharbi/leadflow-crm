<?php

namespace App\Domain\Leads;

use App\Domain\Automation\Thresholds;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;

/**
 * The interested leads of a period, by how interested, and how many are being left waiting.
 *
 * The same clock as InterestSla, in one SQL pass for a whole set of leads: a lead's window starts
 * when it reached its level or wrote last, and ends when somebody contacts it. "Late" is a lead
 * whose window has run out with nobody answering — right now, whatever the period says.
 */
class InterestSlaStats
{
    /**
     * @param  Builder<Lead>  $leads  Already scoped (period, project, partner).
     * @return array{levels:list<array{key:string,count:int}>,total:int,late:int,on_time_pct:?float}
     */
    public static function for(Builder $leads): array
    {
        $since = 'greatest(coalesce(leads.interest_level_changed_at, leads.created_at), coalesce(leads.last_customer_message_at, leads.interest_level_changed_at, leads.created_at))';
        $window = collect(InterestSla::KEYS)->map(fn (string $key, string $level) => "when '{$level}' then ".Thresholds::workspace($key))->implode(' ');
        $target = "case leads.interest_level {$window} else 0 end";

        $row = (clone $leads)->reorder()->whereIn('leads.interest_level', array_keys(InterestSla::KEYS))
            ->selectRaw("
                count(*) filter (where leads.interest_level = 'hot') as hot,
                count(*) filter (where leads.interest_level = 'interested') as interested,
                count(*) filter (where leads.interest_level = 'visit_ready') as visit_ready,
                count(*) filter (where leads.interest_level = 'reservation_ready') as reservation_ready,
                count(*) filter (where leads.status not in ('won','lost')
                    and (leads.last_contact_at is null or leads.last_contact_at < {$since})
                    and {$since} + ({$target} * interval '1 minute') < now()) as late")
            ->first();

        $counts = array_map(fn (string $level) => ['key' => $level, 'count' => (int) $row->{$level}], array_keys(InterestSla::KEYS));
        $total = array_sum(array_column($counts, 'count'));
        $late = (int) $row->late;

        return [
            'levels' => $counts,
            'total' => $total,
            'late' => $late,
            'on_time_pct' => $total > 0 ? round(($total - $late) / $total * 100, 1) : null,
        ];
    }
}
