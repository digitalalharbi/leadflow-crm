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

    /**
     * Re-score after a rep has worked the lead.
     *
     * Recomputed from the lead's own attributes plus a stage bonus — never
     * accumulated onto the stored value, or moving a lead back and forth between
     * stages would ratchet it to 100 and poison the "hot leads" figures.
     */
    public function rescore(Lead $lead): int
    {
        $base = $this->scoreAttributes($lead);

        $stageBonus = match ($lead->status->value) {
            'contacted' => 5,
            'qualified' => 20,
            'appointment' => 30,
            'won' => 30,
            default => 0,
        };

        return max(0, min(100, $base + $stageBonus));
    }

    /** The same signals as scoreIncoming, read off a persisted lead. */
    private function scoreAttributes(Lead $lead): int
    {
        $score = 20;

        if (filled($lead->phone_normalized)) {
            $score += 20;
        }
        if (filled($lead->email)) {
            $score += 5;
        }
        if (filled($lead->city)) {
            $score += 5;
        }
        if ($lead->project_id !== null) {
            $score += 10;
        }
        if ($lead->budget_min !== null || $lead->budget_max !== null) {
            $score += 15;
        }
        if ($lead->payment_method === 'cash') {
            $score += 15;
        } elseif (filled($lead->payment_method)) {
            $score += 5;
        }
        if (in_array($lead->purchase_timeline, ['immediate', 'within_month'], true)) {
            $score += 15;
        } elseif ($lead->purchase_timeline === 'within_3_months') {
            $score += 8;
        }
        if (filled($lead->preferred_property_type)) {
            $score += 5;
        }

        return min(100, $score);
    }
}
