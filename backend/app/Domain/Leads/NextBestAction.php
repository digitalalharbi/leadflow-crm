<?php

namespace App\Domain\Leads;

use App\Enums\LeadStatus;
use App\Models\Lead;

/**
 * What to do with this lead, right now, and why.
 *
 * A rep opening a list of forty leads has to decide which one matters, and the
 * honest answer is that they mostly cannot — so they work top to bottom, or
 * they work the ones they remember. This turns that judgement into something
 * the system makes once, consistently, and can explain.
 *
 * Deliberately rule-based rather than learned: a rep will not trust advice it
 * cannot justify, and "call now because this lead has been waiting 40 minutes
 * and is hot" is justification. A model that cannot say why gets ignored, and
 * ignored advice is worse than none because it costs screen space and trust.
 *
 * Ordered by urgency, first match wins.
 */
class NextBestAction
{
    /**
     * @return array{action:string, urgency:string, reason:string, due_in_minutes:?int}
     */
    /**
     * @param  int  $target  The workspace's first-contact target, in minutes.
     *                       Passed in rather than read from the lead's tenant:
     *                       this is called for every lead in a queue, and
     *                       resolving the relation per lead would be fifty
     *                       loads for one number that is the same every time.
     */
    public function for(Lead $lead, int $target = 30): array
    {
        $minutesSinceCreated = (int) $lead->created_at->diffInMinutes(now());

        // Nothing to do, and saying so is a real answer — it is what lets a
        // queue be empty rather than endless.
        if ($lead->status->isClosed()) {
            return $this->result('none', 'none',
                'هذا العميل مُغلق. لا إجراء مطلوب.');
        }

        if ($lead->assigned_to === null) {
            return $this->result('assign', 'high',
                'لا مسؤول على هذا العميل. أسنده قبل أن يتأخر.');
        }

        /*
         * "Never contacted" has to mean the same thing here as it does on the
         * badge the rep is looking at. Reading the timestamp alone was enough
         * to put "عميل جديد" directly beneath a "تم التواصل" badge on the work
         * screen — each field was right, the pair was nonsense. The status now
         * has to agree before this branch claims the lead is untouched.
         */
        $neverContacted = $lead->first_contact_at === null
            && ! $lead->status->impliesContactWasMade();

        // Never contacted, past the workspace's own target. The single most
        // expensive failure in this business: an ad lead answered tomorrow is
        // a cold call, and the money is already spent.
        if ($neverContacted && $minutesSinceCreated >= $target) {
            return $this->result('call_now', 'critical',
                "لم يتم التواصل منذ {$minutesSinceCreated} دقيقة — تجاوز هدف {$target} دقيقة.",
                0);
        }

        if ($neverContacted) {
            $remaining = max(0, $target - $minutesSinceCreated);

            return $this->result('call_now', 'high',
                "عميل جديد. تبقّى {$remaining} دقيقة على هدف أول تواصل.",
                $remaining);
        }

        // A reply is the strongest buying signal there is, and the window is
        // short — so it outranks a scheduled follow-up.
        if ($lead->first_response_at !== null && $lead->status === LeadStatus::Replied) {
            return $this->result('reply_now', 'critical',
                'العميل ردّ ولم يُتابَع بعد. هذه أقوى إشارة شراء.',
                0);
        }

        if ($lead->next_follow_up_at !== null) {
            $dueIn = (int) now()->diffInMinutes($lead->next_follow_up_at, false);

            if ($dueIn < 0) {
                return $this->result('follow_up', 'critical',
                    'متابعة متأخرة بـ '.abs($dueIn).' دقيقة.', $dueIn);
            }

            if ($dueIn <= 120) {
                return $this->result('follow_up', 'high',
                    "متابعة مستحقة خلال {$dueIn} دقيقة.", $dueIn);
            }

            return $this->result('wait', 'low',
                'المتابعة مجدولة. لا شيء مطلوب الآن.', $dueIn);
        }

        // Contacted, qualified, warm — and nothing scheduled. This is the lead
        // that quietly goes cold, because nothing about it looks urgent.
        if ($lead->status === LeadStatus::Qualified || $lead->status === LeadStatus::Negotiation) {
            return $this->result('book_meeting', 'high',
                'مؤهل وبلا خطوة تالية. احجز موعدًا قبل أن يبرد.');
        }

        return $this->result('schedule_follow_up', 'medium',
            'لا توجد خطوة تالية محددة لهذا العميل.');
    }

    private function result(string $action, string $urgency, string $reason, ?int $dueIn = null): array
    {
        return [
            'action' => $action,
            'urgency' => $urgency,
            'reason' => $reason,
            'due_in_minutes' => $dueIn,
        ];
    }

    /** Sorting weight, so a queue can put the most urgent first. */
    public function weight(array $action): int
    {
        return match ($action['urgency']) {
            'critical' => 0,
            'high' => 1,
            'medium' => 2,
            'low' => 3,
            default => 4,
        };
    }
}
