<?php

namespace App\Services\Leads;

use App\Enums\ActivityType;
use App\Enums\FollowUpOutcome;
use App\Enums\LeadTemperature;
use App\Enums\QualificationStatus;
use App\Models\Lead;
use App\Models\LeadEngagement;

/**
 * Why a lead is worth calling, as a number and as a list of reasons.
 *
 * A score nobody can argue with is a score nobody trusts. Every rule that fires
 * comes back with a label, so the lead screen can show "ميزانية مطابقة +25"
 * rather than an unexplained 78 — and a sales manager who disagrees knows
 * exactly which rule to change.
 *
 * Weights live in config/scoring.php so a later release can make them a tenant
 * setting without touching this class. Nothing about who the person is may ever
 * enter the calculation: only interest, fit, budget, timing, financing,
 * engagement and whether they want an appointment.
 */
class LeadScoringService
{
    /** The lead being evaluated, so finalise() can read its tenant's thresholds. */
    private ?Lead $lead = null;

    /** Timelines that count as "buying soon". */
    private const SOON = ['immediate', 'within_month', 'within_1_month', 'within_3_months'];

    private const WITHIN_6_MONTHS = ['within_6_months', 'three_to_six_months'];

    /**
     * @return array{score: int, temperature: LeadTemperature, reasons: array<int, array{key: string, label: string, points: int}>}
     */
    public function evaluate(Lead $lead): array
    {
        $this->lead = $lead;

        $weights = config('scoring.weights');
        $secondary = config('scoring.secondary');
        $reasons = [];

        $add = function (string $key, string $label, int $points) use (&$reasons) {
            if ($points !== 0) {
                $reasons[] = ['key' => $key, 'label' => $label, 'points' => $points];
            }
        };

        $score = (int) config('scoring.baseline');

        // A number we cannot call is worth nothing, whatever else it says.
        if ($this->isWrongNumber($lead)) {
            $add('wrong_number', 'رقم خاطئ', $weights['wrong_number']);

            return $this->finalise($score + $weights['wrong_number'], $reasons);
        }

        $budget = $this->budgetFit($lead);

        if ($budget === 'matched') {
            $add('budget_matched', 'الميزانية مطابقة للمشروع', $weights['budget_matched']);
            $score += $weights['budget_matched'];
        } elseif ($budget === 'mismatch') {
            $add('budget_mismatch', 'الميزانية أقل من سعر المشروع', $weights['budget_mismatch']);
            $score += $weights['budget_mismatch'];
        } elseif ($budget === 'known') {
            $add('budget_known', 'الميزانية معروفة', $secondary['budget_known']);
            $score += $secondary['budget_known'];
        }

        if (in_array($lead->purchase_timeline, self::SOON, true)) {
            $add('timeline_soon', 'ينوي الشراء خلال 3 أشهر', $weights['timeline_within_3_months']);
            $score += $weights['timeline_within_3_months'];
        } elseif (in_array($lead->purchase_timeline, self::WITHIN_6_MONTHS, true)) {
            $add('timeline_6_months', 'ينوي الشراء خلال 6 أشهر', $secondary['timeline_within_6_months']);
            $score += $secondary['timeline_within_6_months'];
        }

        if ($lead->financing_status === 'approved') {
            $add('financing_approved', 'لديه موافقة تمويلية', $weights['financing_approved']);
            $score += $weights['financing_approved'];
        } elseif ($lead->financing_status === 'in_progress') {
            $add('financing_in_progress', 'التمويل تحت الإجراء', $secondary['financing_in_progress']);
            $score += $secondary['financing_in_progress'];
        } elseif ($lead->payment_method === 'cash') {
            $add('payment_cash', 'سيشتري كاش', $secondary['payment_cash']);
            $score += $secondary['payment_cash'];
        }

        if ($lead->appointment_interest === true) {
            $add('wants_appointment', 'يرغب بموعد زيارة', $weights['wants_appointment']);
            $score += $weights['wants_appointment'];
        }

        if ($lead->first_response_at !== null) {
            $add('replied', 'رد على رسائل واتساب', $weights['replied_on_whatsapp']);
            $score += $weights['replied_on_whatsapp'];
        }

        foreach ([
            'purpose_known' => [filled($lead->purchase_purpose) && $lead->purchase_purpose !== 'unknown', 'غرض الشراء معروف'],
            'property_type_known' => [filled($lead->preferred_property_type), 'نوع العقار معروف'],
            'location_known' => [filled($lead->preferred_location), 'الموقع المفضل معروف'],
            'project_known' => [$lead->project_id !== null, 'مرتبط بمشروع'],
        ] as $key => [$condition, $label]) {
            if ($condition) {
                $add($key, $label, $secondary[$key]);
                $score += $secondary[$key];
            }
        }

        // Someone a rep has actually spoken to is further along than someone
        // sitting untouched in the queue with the same answers.
        if ($lead->first_contact_at !== null) {
            $add('contacted', 'تم التواصل معه', $secondary['contacted']);
            $score += $secondary['contacted'];
        }

        $score += $this->engagement($lead, $add);

        return $this->finalise($score, $reasons);
    }

    /**
     * Apply the score to the lead and return what changed, without saving — the
     * caller decides whether this is part of a larger write.
     *
     * @return array{changed: bool, score: int, previous_score: int, temperature: LeadTemperature, previous_temperature: LeadTemperature, reasons: array}
     */
    /**
     * What the customer did, as opposed to what they told us.
     *
     * Behaviour is the better predictor: somebody who opened the brochure twice
     * this morning is further along than somebody who ticked "within three
     * months" in March. So this is the one part of the score that moves without
     * anybody filling anything in.
     *
     * Only the last thirty days, and capped. A lead who opens a link eleven
     * times is interested, not eleven times more interested — and without a
     * ceiling the loudest signal in the product would be a mail client that
     * prefetches images.
     */
    private function engagement(Lead $lead, callable $add): int
    {
        $weights = config('scoring.engagement');

        $events = LeadEngagement::query()
            ->where('lead_id', $lead->id)
            ->where('happened_at', '>=', now()->subDays(LeadEngagement::RECENT_DAYS))
            ->get()
            ->groupBy('type');

        $points = 0;

        if (($opens = $events[LeadEngagement::FILE_OPENED] ?? collect())->isNotEmpty()) {
            $add('file_opened', 'فتح الملف المرسل', $weights['file_opened']);
            $points += $weights['file_opened'];

            // Opening it a second time is a different fact from opening it.
            if ($opens->count() > 1) {
                $add('file_opened_again', 'فتح الملف أكثر من مرة', $weights['file_opened_again']);
                $points += $weights['file_opened_again'];
            }
        }

        foreach ([
            LeadEngagement::EMAIL_OPENED => ['email_opened', 'فتح البريد'],
            LeadEngagement::LINK_CLICKED => ['link_clicked', 'ضغط رابطًا'],
            LeadEngagement::WHATSAPP_REPLIED => ['whatsapp_replied', 'ردّ على واتساب'],
        ] as $type => [$key, $label]) {
            if (($events[$type] ?? collect())->isNotEmpty()) {
                $add($key, $label, $weights[$key]);
                $points += $weights[$key];
            }
        }

        return min($points, (int) $weights['max']);
    }

    public function apply(Lead $lead): array
    {
        $previousScore = (int) $lead->score;
        $previousTemperature = $lead->temperature;

        $result = $this->evaluate($lead);

        $lead->score = $result['score'];
        $lead->temperature = $result['temperature'];

        return [
            'changed' => $previousScore !== $result['score'] || $previousTemperature !== $result['temperature'],
            'score' => $result['score'],
            'previous_score' => $previousScore,
            'temperature' => $result['temperature'],
            'previous_temperature' => $previousTemperature,
            'reasons' => $result['reasons'],
        ];
    }

    /**
     * Re-scores a lead because something happened to it, and keeps the result.
     *
     * `apply` only sets the attributes — every other caller is mid-save — so a
     * caller with nothing else to save (a customer opened an email, pressed a
     * link, opened a brochure) would compute a new score and drop it. Without
     * this the engagement weights in `config/scoring.php` counted for the next
     * time anybody happened to edit the lead, which is not when they matter.
     *
     * @return array{changed:bool,score:int,previous_score:int}
     */
    public function refresh(Lead $lead, string $reason): array
    {
        $result = $this->apply($lead);

        if ($result['changed']) {
            $lead->saveQuietly();

            app(ActivityLogger::class)->log(
                $lead,
                ActivityType::ScoreChanged,
                null,
                null,
                [
                    'from' => $result['previous_score'],
                    'to' => $result['score'],
                    'temperature' => $result['temperature']->value,
                    'reason' => $reason,
                ],
            );
        }

        return $result;
    }

    /**
     * How complete the picture is. Separate from the score: a lead can be fully
     * qualified and still cold, which is exactly the fact a manager needs.
     */
    public function qualificationStatus(Lead $lead): QualificationStatus
    {
        $answered = collect([
            $lead->purchase_purpose,
            $lead->budget_max ?? $lead->budget_min,
            $lead->payment_method,
            $lead->financing_status,
            $lead->preferred_property_type,
            $lead->purchase_timeline,
        ])->filter(fn ($value) => filled($value) && $value !== 'unknown')->count();

        // Appointment interest is an explicit yes/no, so a recorded "no" still
        // counts as something we know.
        if ($lead->appointment_interest !== null) {
            $answered++;
        }

        return match (true) {
            $answered >= 5 => QualificationStatus::Qualified,
            $answered >= 2 => QualificationStatus::PartiallyQualified,
            default => QualificationStatus::Unqualified,
        };
    }

    /** A follow-up outcome is evidence about the lead, so it feeds the score. */
    public function applyFollowUpOutcome(Lead $lead, FollowUpOutcome $outcome): void
    {
        match ($outcome) {
            FollowUpOutcome::Appointment => $lead->appointment_interest = true,
            FollowUpOutcome::Interested => $lead->appointment_interest ??= true,
            FollowUpOutcome::NotInterested => $lead->appointment_interest = false,
            default => null,
        };

        if (in_array($outcome, [FollowUpOutcome::Replied, FollowUpOutcome::Interested, FollowUpOutcome::Appointment], true)) {
            $lead->first_response_at ??= now();
        }
    }

    /**
     * A wrong number is recorded as a lost reason, not a field of its own —
     * reps already reach for "رقم خاطئ" when they close one out.
     */
    private function isWrongNumber(Lead $lead): bool
    {
        return $lead->lostReason?->key === 'wrong_number';
    }

    /** matched | mismatch | known | null */
    private function budgetFit(Lead $lead): ?string
    {
        $budget = $lead->budget_max ?? $lead->budget_min;

        if ($budget === null) {
            return null;
        }

        $price = $lead->project?->starting_price;

        if ($price === null || (float) $price <= 0.0) {
            return 'known';
        }

        $tolerance = (float) config('scoring.budget.tolerance');

        return (float) $budget >= (float) $price * $tolerance ? 'matched' : 'mismatch';
    }

    private function finalise(int $score, array $reasons): array
    {
        $score = max(0, min(100, $score));

        // A tenant's own thresholds win over the defaults: a developer selling
        // one villa a month and one selling two hundred flats do not agree on
        // what "hot" is worth chasing.
        $settings = $this->lead?->tenant?->settings;

        return [
            'score' => $score,
            'temperature' => LeadTemperature::fromScore(
                $score,
                (int) ($settings?->score_warm_threshold ?? config('scoring.temperature.warm_from')),
                (int) ($settings?->score_hot_threshold ?? config('scoring.temperature.hot_from')),
            ),
            'reasons' => $reasons,
        ];
    }
}
