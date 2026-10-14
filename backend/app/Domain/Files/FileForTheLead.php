<?php

namespace App\Domain\Files;

use App\Models\Lead;
use App\Models\ProjectFile;
use Illuminate\Support\Collection;

/**
 * Which file to send, and why that one.
 *
 * A rep sending a brochure today finds it in WhatsApp, on a shared drive, or in
 * an email from March — and sends the Arabic one to an English speaker about as
 * often as not. This picks instead, from what the lead already told us.
 *
 * Every answer carries its reason. "Why did it send that file" has to be
 * answerable later by somebody who was not here, and a chooser that cannot say
 * why is one nobody trusts enough to leave switched on.
 */
class FileForTheLead
{
    /**
     * @return array{file:?ProjectFile,reason:?string}
     */
    public function pick(Lead $lead, ?string $kind = null): array
    {
        $files = $this->available($lead);

        if ($files->isEmpty()) {
            return ['file' => null, 'reason' => 'no_files'];
        }

        $wanted = $kind ?? $this->kindFor($lead);
        $language = $lead->language();

        $ofKind = $files->where('kind', $wanted)->values();

        /*
         * Falling back to a brochure rather than to nothing. A project with an
         * investment deck for some languages and a brochure for all of them
         * should send the brochure to the rest, not stay silent.
         */
        if ($ofKind->isEmpty() && $wanted !== 'brochure') {
            $ofKind = $files->where('kind', 'brochure')->values();
            $wanted = 'brochure';
        }

        if ($ofKind->isEmpty()) {
            return ['file' => null, 'reason' => 'no_files_of_kind'];
        }

        // Their language first; then a file that has none, which suits anybody.
        $exact = $ofKind->first(fn (ProjectFile $file) => $language !== null && $file->language === $language);
        $neutral = $ofKind->first(fn (ProjectFile $file) => $file->language === null);

        $file = $exact ?? $neutral ?? $ofKind->first();

        return [
            'file' => $file,
            'reason' => $this->reasonFor($wanted, $file, $language, $exact !== null),
        ];
    }

    /**
     * Everything this lead's project has to send, in the order somebody chose.
     *
     * @return Collection<int,ProjectFile>
     */
    public function available(Lead $lead): Collection
    {
        if (! $lead->project_id) {
            return collect();
        }

        return ProjectFile::where('project_id', $lead->project_id)
            ->where('is_active', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    /**
     * What this lead should be sent, from what they have already said.
     *
     * Someone buying to invest wants the numbers, not the floor plans, and
     * someone who said they need financing is asking a question the financing
     * guide answers. Neither is a guess: both are fields the lead filled in.
     */
    private function kindFor(Lead $lead): string
    {
        if ($lead->purchase_purpose === 'investment') {
            return 'investment_deck';
        }

        if ($lead->payment_method === 'financing' || $lead->financing_status === 'in_progress') {
            return 'financing_guide';
        }

        return 'brochure';
    }

    /** A key the interface translates, with what decided it. */
    private function reasonFor(string $kind, ProjectFile $file, ?string $language, bool $matchedLanguage): string
    {
        return match (true) {
            $kind === 'investment_deck' => 'investment_purpose',
            $kind === 'financing_guide' => 'needs_financing',
            $matchedLanguage && $language !== null => 'language_'.$language,
            $file->language === null => 'any_language',
            default => 'default_brochure',
        };
    }
}
