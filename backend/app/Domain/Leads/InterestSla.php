<?php

namespace App\Domain\Leads;

use App\Domain\Automation\Thresholds;
use App\Models\Lead;
use Carbon\CarbonInterface;

/**
 * How long an interested lead may wait for a person — one clock per level.
 *
 * A new lead has a first-contact target. An interested one has a different problem: they have
 * already shown they want something, so the cost of silence is higher and the window shorter the
 * further along they are. A reservation request is answered in minutes, a visit within half an
 * hour, a hot lead within a quarter, an interested one within the hour (all four are settings).
 *
 * The clock starts when the lead reached its level or wrote to us last — whichever is later —
 * and stops the moment somebody contacts them. It reads lead columns only, so it is safe to
 * compute for every lead in a queue.
 *
 *   ok        inside the window, more than a quarter of it left
 *   due_soon  a quarter of the window or less left
 *   breached  past the deadline and nobody has answered
 *   done      somebody has contacted them since the clock started
 */
class InterestSla
{
    /** Level → the threshold that sets its window. */
    public const KEYS = [
        'hot' => 'sla_hot_minutes',
        'interested' => 'sla_interested_minutes',
        'visit_ready' => 'sla_visit_minutes',
        'reservation_ready' => 'sla_reservation_minutes',
    ];

    /** @return array{level:string, state:string, target_minutes:int, since:string, deadline:string, minutes_left:int}|null */
    public static function for(Lead $lead, ?CarbonInterface $now = null): ?array
    {
        $level = $lead->interest_level;

        if (! isset(self::KEYS[$level]) || $lead->status?->isClosed()) {
            return null;
        }

        $now ??= now();
        $since = collect([$lead->interest_level_changed_at, $lead->last_customer_message_at])->filter()->max()
            ?? $lead->interest_at ?? $lead->created_at;
        $target = Thresholds::forLead($lead, self::KEYS[$level]);
        $deadline = $since->copy()->addMinutes($target);
        $left = (int) $now->diffInMinutes($deadline, false);

        $done = $lead->last_contact_at !== null && $lead->last_contact_at->gte($since);

        return [
            'level' => $level,
            'state' => match (true) {
                $done => 'done',
                $left < 0 => 'breached',
                $left <= max(1, (int) floor($target / 4)) => 'due_soon',
                default => 'ok',
            },
            'target_minutes' => $target,
            'since' => $since->toIso8601String(),
            'deadline' => $deadline->toIso8601String(),
            'minutes_left' => $left,
        ];
    }

    /** Extra points in the queue for how far along the lead is: nobody who asked to reserve waits behind a warm one. */
    public static function rankPoints(?string $level): int
    {
        return ['reservation_ready' => 20, 'visit_ready' => 16, 'hot' => 12, 'interested' => 8, 'warm' => 3][$level] ?? 0;
    }
}
