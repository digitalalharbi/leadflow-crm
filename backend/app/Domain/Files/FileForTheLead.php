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
 * often as not. This picks instead, from what the product already knows about
 * the lead: their project, the campaign that brought them, their language,
 * what kind of buyer they said they are, and where they are in the funnel.
 *
 * Two steps, kept apart because they fail differently:
 *
 *   1. *Eligible* — a file that says it is for a campaign, a stage or an
 *      audience is only a candidate for a lead that matches. A file for the
 *      winter campaign is not a fallback for the summer one.
 *   2. *Ranked* — among the eligible, the one that fits best wins, and every
 *      point it earned is recorded.
 *
 * Every answer carries its reasons. "Why did it send that file" has to be
 * answerable later by somebody who was not here, and a chooser that cannot say
 * why is one nobody trusts enough to leave switched on.
 */
class FileForTheLead
{
    /** What a lead at each stage is best sent, when nothing more specific applies. */
    private const STAGE_KIND = [
        'new' => 'brochure',
        'contacted' => 'brochure',
        'replied' => 'brochure',
        'no_response' => 'brochure',
        'qualified' => 'price_list',
        'appointment' => 'brochure',
        'negotiation' => 'offer',
        'won' => 'contract',
    ];

    private const KIND_FIT = 50;

    private const CAMPAIGN_FIT = 30;

    private const AUDIENCE_FIT = 25;

    private const STAGE_FIT = 20;

    private const LANGUAGE_FIT = 15;

    private const LANGUAGE_NEUTRAL = 5;

    /**
     * @return array{file:?ProjectFile,reason:?string,reasons:array<int,string>}
     */
    public function pick(Lead $lead, ?string $kind = null): array
    {
        $files = $this->available($lead);

        if ($files->isEmpty()) {
            return ['file' => null, 'reason' => 'no_files', 'reasons' => []];
        }

        $eligible = $files->filter(fn (ProjectFile $file) => $this->eligible($file, $lead))->values();

        if ($eligible->isEmpty()) {
            return ['file' => null, 'reason' => 'no_files_for_this_lead', 'reasons' => []];
        }

        $explicit = $kind !== null;
        $wanted = $kind ?? $this->kindFor($lead);

        // The wanted kind, else a brochure rather than nothing: a project with
        // an investment deck for some languages and a brochure for all should
        // send the brochure to the rest, not stay silent.
        $pool = $eligible->where('kind', $wanted)->values();
        $fellBack = false;

        if ($pool->isEmpty() && $wanted !== 'brochure') {
            $pool = $eligible->where('kind', 'brochure')->values();
            $fellBack = true;
            $wanted = 'brochure';
        }

        if ($pool->isEmpty()) {
            return ['file' => null, 'reason' => 'no_files_of_kind', 'reasons' => []];
        }

        $ranked = $pool
            ->map(fn (ProjectFile $file) => $this->score($file, $lead, $wanted, $explicit, $fellBack))
            // Stable on the order somebody chose: `sort`, then age.
            ->sortByDesc('points')
            ->values();

        $best = $ranked->first();

        return [
            'file' => $best['file'],
            'reason' => $best['reasons'][0] ?? 'default_brochure',
            'reasons' => $best['reasons'],
        ];
    }

    /**
     * Everything this lead's project has to send that is meant for somebody like
     * them, in the order somebody chose.
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
     * The kind of buyer this lead has said they are: investor, somebody who
     * needs financing, or somebody buying to live in it. Null when they have
     * said nothing — and nothing is not a guess.
     */
    public function audienceOf(Lead $lead): ?string
    {
        if ($lead->purchase_purpose === 'investment') {
            return 'investor';
        }

        if ($lead->payment_method === 'financing' || $lead->financing_status === 'in_progress') {
            return 'financing';
        }

        return filled($lead->purchase_purpose) && $lead->purchase_purpose !== 'unknown' ? 'end_user' : null;
    }

    /**
     * A file that names a campaign, a stage or an audience is for those and
     * nobody else. One that names nothing is for anybody.
     */
    private function eligible(ProjectFile $file, Lead $lead): bool
    {
        if (filled($file->campaign) && ! $this->sameCampaign($file->campaign, $lead->campaign_name)) {
            return false;
        }

        $stages = (array) $file->stages;

        if ($stages !== [] && ! in_array($lead->status?->value, $stages, true)) {
            return false;
        }

        if (filled($file->audience) && $file->audience !== $this->audienceOf($lead)) {
            return false;
        }

        return true;
    }

    private function sameCampaign(string $fileCampaign, ?string $leadCampaign): bool
    {
        return $leadCampaign !== null && mb_strtolower(trim($fileCampaign)) === mb_strtolower(trim($leadCampaign));
    }

    /** @return array{file:ProjectFile,points:int,reasons:array<int,string>} */
    private function score(ProjectFile $file, Lead $lead, string $wanted, bool $explicit, bool $fellBack): array
    {
        $points = 0;
        $reasons = [];

        if (filled($file->campaign)) {
            $points += self::CAMPAIGN_FIT;
            $reasons[] = 'campaign';
        }

        if (filled($file->audience)) {
            $points += self::AUDIENCE_FIT;
            $reasons[] = match ($file->audience) {
                'investor' => 'investment_purpose',
                'financing' => 'needs_financing',
                default => 'audience_end_user',
            };
        }

        if ((array) $file->stages !== []) {
            $points += self::STAGE_FIT;
            $reasons[] = 'stage_'.$lead->status?->value;
        }

        $language = $lead->language();

        if ($language !== null && $file->language === $language) {
            $points += self::LANGUAGE_FIT;
            $reasons[] = 'language_'.$language;
        } elseif ($file->language === null) {
            $points += self::LANGUAGE_NEUTRAL;
        }

        // The kind, last in the reasons: it is the least informative thing to
        // say — "it is a brochure" — but it is what decides when nothing else did.
        if (! $fellBack) {
            $points += self::KIND_FIT;
            $reasons[] = match (true) {
                $explicit => 'asked_for',
                $wanted === 'investment_deck' && $this->audienceOf($lead) === 'investor' => 'investment_purpose',
                $wanted === 'financing_guide' => 'needs_financing',
                in_array($wanted, ['price_list', 'offer', 'contract'], true) => 'stage_'.$lead->status?->value,
                default => 'default_brochure',
            };
        }

        $reasons = array_values(array_unique($reasons));

        if ($reasons === []) {
            $reasons[] = $file->language === null ? 'any_language' : 'default_brochure';
        }

        return ['file' => $file, 'points' => $points, 'reasons' => $reasons];
    }

    /**
     * What this lead should be sent, from what they have already said, and
     * failing that from where they are in the funnel.
     *
     * Someone buying to invest wants the numbers, not the floor plans, and
     * someone who said they need financing is asking a question the financing
     * guide answers. Neither is a guess: both are fields the lead filled in. A
     * lead who said nothing is sent what their stage suggests.
     */
    private function kindFor(Lead $lead): string
    {
        return match ($this->audienceOf($lead)) {
            'investor' => 'investment_deck',
            'financing' => 'financing_guide',
            default => self::STAGE_KIND[$lead->status?->value] ?? 'brochure',
        };
    }
}
