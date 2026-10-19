<?php

namespace App\Services\Whatsapp;

use App\Domain\Qualification\ProjectQualification;
use App\Models\Lead;
use App\Models\LeadAnswer;
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
    /**
     * What each placeholder resolves to for this lead, or null where nothing
     * honest can be said.
     *
     * Exposed because a Meta template is not rendered by us at all — Meta
     * substitutes the variables itself, and refuses any that are empty. The
     * caller needs the raw values to decide whether the template can be sent
     * rather than a sentence with the gaps already closed up.
     *
     * @return array<string,?string>
     */
    public function values(Lead $lead, ?User $agent = null, ?string $company = null): array
    {
        return [
            // A lead that arrived as a bare phone number has no first name, and
            // greeting someone by their own phone number is worse than not
            // greeting them — so an unusable name is dropped like any other
            // placeholder we cannot fill.
            'name' => $lead->first_name ?: $this->givenName($lead->full_name),
            'agent' => $agent?->name,
            'company' => $company ?: $lead->tenant?->branding?->company_name ?: $lead->tenant?->name,
            'project' => $lead->project?->name,
            'questions' => $this->questions($lead),
        ];
    }

    public function render(string $template, Lead $lead, ?User $agent = null, ?string $company = null): string
    {
        $values = $this->values($lead, $agent, $company);

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
     * The first word of a full name, or nothing if it is not a name at all.
     *
     * "مرحبًا عبدالله" reads as a person; "مرحبًا عبدالله الكريم المحترم" reads
     * as a form letter, and "مرحبًا 0557778888" reads as a machine.
     */
    private function givenName(?string $fullName): ?string
    {
        $trimmed = trim((string) $fullName);

        if ($trimmed === '') {
            return null;
        }

        $first = preg_split('/\s+/u', $trimmed, 2)[0] ?? '';

        // Digits, punctuation or an email landed in the name column during
        // ingestion. None of those belong in a greeting.
        return preg_match('/^\p{L}/u', $first) === 1 ? $first : null;
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

    /**
     * The project's questions this lead has not answered, numbered.
     *
     * Null when there are none, so a qualification message is never sent with
     * an empty list where the questions should be.
     */
    private function questions(Lead $lead): ?string
    {
        $answered = LeadAnswer::where('lead_id', $lead->id)->pluck('project_question_id')->all();

        $questions = app(ProjectQualification::class)
            ->questionsFor($lead)
            ->reject(fn ($question) => in_array($question->id, $answered, true))
            ->values();

        if ($questions->isEmpty()) {
            return null;
        }

        return $questions->map(fn ($question, $index) => ($index + 1).'. '.$question->label)->implode("\n");
    }
}
