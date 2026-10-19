<?php

namespace App\Domain\Automation;

use App\Models\Lead;
use App\Models\MessageTemplate;

/**
 * "The welcome message", resolved for one lead.
 *
 * A journey is written once for the whole workspace and names what a message
 * is for, never which template — because the right welcome for a lead on
 * Riyadh Gardens who writes in English is not the right one for a lead on the
 * coast project who writes in Arabic, and a rule per project per language is
 * the configuration nobody keeps up to date.
 *
 * Each candidate is scored on how closely it was written for this lead:
 * the lead's own project beats a workspace-wide template, which beats nothing;
 * a template for another project is never used. Language, stage and the kind of
 * buyer break ties. Equal scores fall back to the order the workspace set.
 */
class TemplatePicker
{
    public function pick(Lead $lead, string $channel, string $purpose): ?MessageTemplate
    {
        $candidates = MessageTemplate::query()
            ->where('channel', $channel)
            ->where('purpose', $purpose)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $lead->project_id))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $language = $lead->language();
        $stage = $lead->status?->value;
        $audience = match (true) {
            $lead->purchase_purpose === 'investment' => 'investment',
            filled($lead->purchase_purpose) && $lead->purchase_purpose !== 'unknown' => 'end_user',
            default => null,
        };

        return $candidates
            ->map(fn (MessageTemplate $template) => [
                'template' => $template,
                'score' => $this->fit($template->project_id, $lead->project_id, 4)
                    + $this->fit($template->language, $language, 2)
                    + $this->fit($template->stage, $stage, 2)
                    + $this->fit($template->audience, $audience, 1),
            ])
            ->filter(fn (array $row) => $row['score'] >= 0)
            ->sortByDesc('score')
            ->first()['template'] ?? null;
    }

    /**
     * Written for exactly this: the full weight. Written for anyone: a little.
     * Written for somebody else: disqualified.
     */
    private function fit(mixed $templateValue, mixed $leadValue, int $weight): int
    {
        if ($templateValue === null || $templateValue === '') {
            return 0;
        }

        return (string) $templateValue === (string) $leadValue ? $weight : -100;
    }
}
