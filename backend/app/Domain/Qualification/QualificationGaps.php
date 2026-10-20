<?php

namespace App\Domain\Qualification;

use App\Models\Lead;

/**
 * What a rep or the automation still needs to learn about a lead — and the one thing to ask next.
 *
 * Read from what the lead record holds, wherever it came from (the form, a reply, a rep's note, the
 * conversation), so a question is never asked that has an answer. The order is the order a person
 * would ask in: which project, buy or rent, how much, where, what kind, how many rooms, how they
 * will pay, when, a visit, a reservation.
 */
class QualificationGaps
{
    /** The questions, in the order they are asked. */
    public const ORDER = ['project', 'transaction', 'budget', 'district', 'property_type', 'bedrooms', 'payment', 'timeline', 'visit', 'reservation'];

    /** @return array{known:list<string>, gaps:list<string>, next:?string, total:int, percent:int} */
    public function for(Lead $lead): array
    {
        $known = collect(self::ORDER)->filter(fn (string $key) => $this->isKnown($lead, $key))->values();
        $gaps = collect(self::ORDER)->reject(fn (string $key) => $known->contains($key) || ! $this->applies($lead, $key))->values();
        $applicable = $known->count() + $gaps->count();

        return [
            'known' => $known->all(),
            'gaps' => $gaps->all(),
            'next' => $gaps->first(),
            'total' => $applicable,
            'percent' => $applicable > 0 ? (int) round($known->count() / $applicable * 100) : 0,
        ];
    }

    private function isKnown(Lead $lead, string $key): bool
    {
        $filled = fn ($v) => filled($v) && $v !== 'unknown';

        return match ($key) {
            'project' => $lead->project_id !== null,
            'transaction' => $filled($lead->transaction_type),
            'budget' => $lead->budget_max !== null || $lead->budget_min !== null,
            'district' => $filled($lead->preferred_district) || $filled($lead->preferred_location),
            'property_type' => $filled($lead->preferred_property_type),
            'bedrooms' => $lead->bedrooms !== null,
            'payment' => $filled($lead->payment_method),
            'timeline' => $filled($lead->purchase_timeline),
            // A "no" is an answer too: only a lead nobody has asked has no value.
            'visit' => $lead->appointment_interest !== null,
            'reservation' => (bool) $lead->reservation_intent,
            default => false,
        };
    }

    /** Rooms mean nothing for a plot or a shop. */
    private function applies(Lead $lead, string $key): bool
    {
        if ($key === 'bedrooms') {
            return ! in_array($lead->preferred_property_type, ['land', 'commercial', 'office', 'shop'], true);
        }

        return true;
    }
}
