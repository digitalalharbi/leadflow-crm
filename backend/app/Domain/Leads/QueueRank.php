<?php

namespace App\Domain\Leads;

use App\Domain\Automation\Thresholds;
use App\Models\Lead;

/**
 * Why this lead is ahead of that one.
 *
 * Urgency decides the bucket and is never overridden — a lead past its
 * first-contact target is not outranked by a nice-looking one that can wait.
 * Inside a bucket, seven things decide, each worth a bounded number of points
 * so no single one can bury the others:
 *
 *   SLA risk    how close the action is to being late (the bucket itself)
 *   score       the lead's own score                        up to 15
 *   intent      what they have said or done toward buying   up to 10
 *   engagement  how much they interact with what we send    up to 10
 *   recency     how recently they spoke                     up to 10
 *   deal value  what it is worth                            up to 8
 *   campaign    quality of the campaign that brought them   -3 … +5
 *
 * The three biggest contributions are named in the result, in words, so the
 * rep sees why the queue is in the order it is.
 */
class QueueRank
{
    private const URGENCY = ['critical' => 40, 'high' => 28, 'medium' => 16, 'low' => 6];

    /**
     * @param  array{action:string,urgency:string}  $action
     * @return array{score:int, within:int, reasons:list<array{key:string,points:int}>, reason:string}
     */
    public function for(Lead $lead, array $action): array
    {
        $intel = $lead->relationLoaded('intelligence') ? $lead->getRelation('intelligence') : null;

        $parts = [
            'sla' => self::URGENCY[$action['urgency']] ?? 0,
            'score' => (int) round(min(100, max(0, (int) $lead->score)) * 0.15),
            'intent' => (int) round(($intel['intent']['value'] ?? 0) * 0.10),
            'engagement' => (int) round(($intel['engagement']['value'] ?? 0) * 0.10),
            'recency' => $this->recency($lead),
            'value' => $this->value($lead),
            'campaign' => $this->campaign($lead),
        ];

        $within = $parts['score'] + $parts['intent'] + $parts['engagement'] + $parts['recency'] + $parts['value'] + $parts['campaign'];

        $reasons = collect($parts)
            ->map(fn (int $points, string $key) => ['key' => $key, 'points' => $points])
            ->filter(fn (array $part) => $part['points'] > 0)
            // SLA is only worth naming when there is a clock running.
            ->reject(fn (array $part) => $part['key'] === 'sla' && ($action['urgency'] === 'low' || $action['urgency'] === 'none'))
            ->sortByDesc('points')
            ->take(3)
            ->values();

        return [
            'score' => array_sum($parts),
            'within' => $within,
            'reasons' => $reasons->all(),
            'reason' => $reasons->map(fn (array $part) => $this->words($part['key'], $lead, $action, $intel))->filter()->implode(' · '),
        ];
    }

    /** @param  array<string,mixed>|null  $intel */
    private function words(string $key, Lead $lead, array $action, ?array $intel): ?string
    {
        return match ($key) {
            'sla' => match ($action['urgency']) {
                'critical' => 'مهلة متأخرة أو وشيكة',
                'high' => 'يقترب موعده',
                default => 'يستحق الاهتمام',
            },
            'score' => "تقييم {$lead->score}",
            'intent' => 'نية شراء عالية',
            'engagement' => 'تفاعل جيد',
            'recency' => 'ردّ مؤخرًا',
            'value' => 'قيمة صفقة كبيرة',
            'campaign' => 'من حملة جيدة',
            default => null,
        };
    }

    private function recency(Lead $lead): int
    {
        $spoke = $lead->first_response_at ? max($lead->last_contact_at, $lead->first_response_at) : null;
        $latest = $lead->relationLoaded('intelligence') ? ($lead->getRelation('intelligence')['staleness']['days'] ?? null) : null;

        if ($latest === null && $spoke === null) {
            return 0;
        }

        // Fresh activity is what a rep can still act on.
        return match (true) {
            $latest !== null && $latest < 1 => 10,
            $latest !== null && $latest < 3 => 6,
            $latest !== null && $latest < 7 => 3,
            default => 0,
        };
    }

    private function value(Lead $lead): int
    {
        $major = $lead->deal_value_minor !== null ? $lead->deal_value_minor / 100 : (float) ($lead->budget_max ?? 0);

        // Tiers are fractions of the workspace's (or the project's) own "high value" line.
        $line = Thresholds::forLead($lead, 'high_value_from');

        return match (true) {
            $major >= $line * 2 => 8,
            $major >= $line => 5,
            $major >= $line * 0.4 => 3,
            default => 0,
        };
    }

    private function campaign(Lead $lead): int
    {
        $quality = $lead->relationLoaded('campaignRef') ? $lead->campaignRef?->quality_score : null;

        return match (true) {
            $quality === null => 0,
            $quality >= 70 => 5,
            $quality < 30 => -3,
            default => 0,
        };
    }
}
