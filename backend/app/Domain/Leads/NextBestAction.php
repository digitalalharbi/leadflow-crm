<?php

namespace App\Domain\Leads;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Support\ArabicDuration;

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
        return $this->boostPriority($lead, $this->decide($lead, $target));
    }

    /**
     * A priority lead is worked a step earlier than the same situation would be
     * for anybody else, and the reason says so. Never promoted to critical from
     * "nothing to do" — priority changes the order of work, it does not invent
     * work.
     */
    private function boostPriority(Lead $lead, array $result): array
    {
        if (! $lead->is_priority || in_array($result['action'], ['none', 'wait', 'review_fit'], true)) {
            return $result;
        }

        $bumped = ['medium' => 'high', 'high' => 'critical'][$result['urgency']] ?? $result['urgency'];

        return array_merge($result, [
            'urgency' => $bumped,
            'reason' => 'أولوية — '.$result['reason'],
        ]);
    }

    private function decide(Lead $lead, int $target): array
    {
        $minutesSinceCreated = (int) $lead->created_at->diffInMinutes(now());

        // Nothing to do, and saying so is a real answer — it is what lets a
        // queue be empty rather than endless.
        if ($lead->status->isClosed()) {
            return $this->result('none', 'none',
                'هذا العميل مُغلق. لا إجراء مطلوب.');
        }

        /*
         * The answers say this is not a buyer for this project. Not closed — a
         * ticked box is a claim — but not worked as a hot lead either: a person
         * is asked to look, and the reason says why.
         */
        if ($lead->wrong_fit) {
            return $this->result('review_fit', 'medium',
                'إجاباته تدل على أنه غير مناسب لهذا المشروع. راجعه ثم أغلقه أو صحّح الإجابة.');
        }

        /*
         * Automation handed this lead over and nobody has picked it up since.
         * The hand-off reason is the "why now": the customer answered the
         * journey, passed its questions, or stayed silent through all of it.
         */
        if ($lead->automation_state === Lead::AUTOMATION_HANDED_OFF
            && $lead->handed_off_at !== null
            && ($lead->last_contact_at === null || $lead->last_contact_at->lte($lead->handed_off_at))) {
            $handoff = match ($lead->needs_human_reason) {
                'replied' => $this->result('reply_now', 'critical',
                    'ردّ العميل على رسالة الأتمتة — أكمل نفس المحادثة الآن.', 0),
                'qualified' => $this->result('call_now', 'critical',
                    'اجتاز أسئلة التأهيل — اتصل واحجز موعدًا.', 0),
                'no_reply' => $this->result('call_now', 'high',
                    'لم يرد على الرسائل الآلية — جرّب الاتصال.'),
                default => null,
            };

            if ($handoff !== null) {
                return $handoff;
            }
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
                'لم يتم التواصل منذ '.ArabicDuration::fromMinutes($minutesSinceCreated).' — تجاوز هدف '.ArabicDuration::fromMinutes($target).'.',
                0);
        }

        if ($neverContacted) {
            $remaining = max(0, $target - $minutesSinceCreated);

            return $this->result('call_now', 'high',
                'عميل جديد. تبقّى '.ArabicDuration::fromMinutes($remaining).' على هدف أول تواصل.',
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
                    'متابعة متأخرة '.ArabicDuration::fromMinutes($dueIn).'.', $dueIn);
            }

            if ($dueIn <= 120) {
                return $this->result('follow_up', 'high',
                    'متابعة مستحقة خلال '.ArabicDuration::fromMinutes($dueIn).'.', $dueIn);
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

        return $this->fromIntelligence($lead) ?? $this->result('schedule_follow_up', 'medium',
            'لا توجد خطوة تالية محددة لهذا العميل.');
    }

    /**
     * When nothing is scheduled and nothing is overdue, what the customer has
     * been doing decides: engaged and silent gets a message on the channel
     * they can be reached on, a brochure nobody has sent gets sent, a lead that
     * has gone quiet gets nurtured — and one that has ignored every attempt for
     * weeks is suggested for closing. Needs the `intelligence` relation.
     */
    private function fromIntelligence(Lead $lead): ?array
    {
        $intel = $lead->relationLoaded('intelligence') ? $lead->getRelation('intelligence') : null;

        if (! is_array($intel)) {
            return null;
        }

        $why = $intel['reason'];
        $channels = $intel['contactability']['channels'];
        $days = $intel['staleness']['days'];
        $counts = $intel['counts'];
        $engaged = $counts['file_opened'] + $counts['email_opened'] + $counts['link_clicked'] > 0;
        $silent = $counts['replies'] === 0 && $lead->first_response_at === null;

        // High intent and not yet in the sales stages: the call is worth more than a message.
        if ($intel['intent']['value'] >= 65 && $channels['call']) {
            return $this->result('call_now', 'high', ($why ?? 'نية شراء عالية').' — اتصل الآن.');
        }

        if ($engaged && $silent && ($channels['whatsapp'] || $channels['email'])) {
            return $this->result($channels['whatsapp'] ? 'send_whatsapp' : 'send_email', 'medium', ($why ?? 'تفاعل مع ما أُرسل').' — راسله.');
        }

        if ($intel['brochure']['available'] && ! $intel['brochure']['shared'] && ($channels['whatsapp'] || $channels['email'])) {
            return $this->result('send_brochure', 'medium', 'لم يصله بروشور المشروع بعد.');
        }

        if ($silent && $counts['outbound_30d'] >= 3 && $days >= 21) {
            return $this->result('close_lost', 'low', "بلا رد منذ {$days} يومًا بعد {$counts['outbound_30d']} محاولات.");
        }

        if ($days >= 7 && ($channels['whatsapp'] || $channels['email'])) {
            return $this->result('nurture', 'low', "ساكن منذ {$days} أيام — رسالة رعاية.");
        }

        return null;
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
