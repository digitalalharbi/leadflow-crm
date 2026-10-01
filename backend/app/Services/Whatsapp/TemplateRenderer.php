<?php

namespace App\Services\Whatsapp;

use App\Models\Lead;
use App\Models\User;

/**
 * Fills {{name}}, {{agent}}, {{company}} and {{project}} in a saved message.
 *
 * The rule that matters: a placeholder we cannot fill is removed, never sent.
 * A customer receiving "بخصوص اهتمامك بمشروع {{project}}" is worse than one
 * receiving a shorter sentence — it tells them a machine sent it and nobody
 * read it first.
 */
class TemplateRenderer
{
    public function render(string $template, Lead $lead, ?User $agent = null, ?string $company = null): string
    {
        $values = [
            'name' => $lead->first_name ?: $this->firstWord($lead->full_name),
            'agent' => $agent?->name,
            'company' => $company ?: $lead->tenant?->branding?->company_name ?: $lead->tenant?->name,
            'project' => $lead->project?->name,
        ];

        $rendered = $template;

        foreach ($values as $key => $value) {
            $rendered = filled($value)
                ? str_replace('{{'.$key.'}}', (string) $value, $rendered)
                : $this->dropPlaceholder($rendered, $key);
        }

        // Anything we do not know about is dropped too, for the same reason.
        $rendered = preg_replace('/\{\{\s*[a-z_]+\s*\}\}/i', '', $rendered) ?? $rendered;

        return $this->tidy($rendered);
    }

    /**
     * Remove the placeholder along with the preposition that introduces it, so
     * "بخصوص اهتمامك بمشروع {{project}}" does not become a dangling "بمشروع".
     */
    private function dropPlaceholder(string $text, string $key): string
    {
        $patterns = [
            '/\s*(?:بخصوص|بمشروع|في مشروع|لمشروع|من)?\s*\{\{\s*'.$key.'\s*\}\}/u',
            '/\{\{\s*'.$key.'\s*\}\}/u',
        ];

        foreach ($patterns as $pattern) {
            $replaced = preg_replace($pattern, '', $text);
            if ($replaced !== null && $replaced !== $text) {
                return $replaced;
            }
        }

        return $text;
    }

    /** Collapse the whitespace and stray punctuation a removal leaves behind. */
    private function tidy(string $text): string
    {
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+([،,.؟!])/u', '$1', $text) ?? $text;
        $text = preg_replace('/([،,])\s*([.؟!])/u', '$2', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
