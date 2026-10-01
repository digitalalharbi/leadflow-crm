<?php

namespace App\Services\Leads;

use App\DataTransferObjects\IncomingLead;
use App\Models\Lead;

/**
 * Deterministic 0-100 score. No AI in the MVP — just signal completeness and
 * intent markers, so the number is explainable to a sales manager.
 */
class LeadScorer
{
    public function scoreIncoming(IncomingLead $incoming): int
    {
        $score = 20; // a real inbound lead starts above zero

        if (filled($incoming->phone)) {
            $score += 20;
        }
        if (filled($incoming->email)) {
            $score += 5;
        }
        if (filled($incoming->city)) {
            $score += 5;
        }
        if ($incoming->projectId !== null) {
            $score += 10;
        }
        if ($incoming->budgetMin !== null || $incoming->budgetMax !== null) {
            $score += 15;
        }
        if ($incoming->paymentMethod === 'cash') {
            $score += 15;
        } elseif (filled($incoming->paymentMethod)) {
            $score += 5;
        }
        if (in_array($incoming->purchaseTimeline, ['immediate', 'within_month'], true)) {
            $score += 15;
        } elseif ($incoming->purchaseTimeline === 'within_3_months') {
            $score += 8;
        }
        if (filled($incoming->preferredPropertyType)) {
            $score += 5;
        }

        return min(100, $score);
    }

    /** Re-score after a sales rep has worked the lead; progress through the pipeline counts. */
    public function rescore(Lead $lead): int
    {
        $score = $lead->score;

        $score += match ($lead->status->value) {
            'contacted' => 5,
            'qualified' => 20,
            'appointment' => 30,
            default => 0,
        };

        return max(0, min(100, $score));
    }
}
