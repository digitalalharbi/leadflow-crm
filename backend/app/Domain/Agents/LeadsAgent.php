<?php

namespace App\Domain\Agents;

use App\Domain\Campaigns\CampaignMetrics;
use App\Models\Campaign;
use Carbon\CarbonImmutable;

/**
 * Measures how real each campaign's leads are, and says when that changes.
 *
 * The score is the answer to "forms or customers?" in one number, built from
 * how far the last 30 days of leads got:
 *
 *   15  valid          (not invalid data)
 *   25  engaged        (replied or moved past contact)
 *   35  qualified      full marks at 50% qualified
 *   15  appointments   full marks at 20% booked
 *   10  won            full marks at 5% closed
 *
 * The full-marks points are deliberately reachable: a campaign where half the
 * leads qualify is an excellent campaign, and a scale on which nothing ever
 * scores well is a scale nobody reads.
 *
 * It feeds back in two directions: into lead scores (a lead from a campaign
 * whose leads are real starts a little higher), and to the people buying the
 * media (an alert when a campaign's quality falls by fifteen points or more).
 */
class LeadsAgent
{
    public const MIN_LEADS = 10;

    public const DROP_ALERT = 15;

    /** "Qualified rate below 10% on more than 50 leads" — the campaign quality warning. */
    public const QUALITY_FLOOR = 0.10;

    public const QUALITY_MIN_LEADS = 50;

    /** @return list<Finding> campaigns whose quality fell */
    public function review(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $rows = (new CampaignMetrics(null, $today->subDays(29), $today))->rows();
        $campaigns = Campaign::query()->whereIn('uuid', array_column($rows, 'id'))->get()->keyBy('uuid');
        $drops = [];

        foreach ($rows as $row) {
            if ($row['leads'] < self::MIN_LEADS) {
                continue;
            }

            $campaign = $campaigns[$row['id']];
            $score = self::score($row);
            $previous = $campaign->quality_score;

            $campaign->forceFill([
                'quality_previous' => $previous,
                'quality_score' => $score,
                'quality_scored_at' => now(),
            ])->save();

            $leads = max(1, (int) $row['leads']);

            /*
             * Marketing's side of the loop: a campaign bringing plenty of
             * leads and few real buyers. Said to the team as a warning, never
             * acted on — pausing a campaign goes through the approval policy.
             */
            if ($row['leads'] > self::QUALITY_MIN_LEADS && ($row['qualified'] / $leads) < self::QUALITY_FLOOR) {
                $drops[] = new Finding('low_qualified_rate', 'bad', $campaign, ['qualified_rate' => round($row['qualified'] / $leads * 100, 1), 'leads' => $row['leads']]);
            } elseif ($row['leads'] > 20 && (($row['leads'] - $row['valid']) / $leads) > 0.3) {
                $drops[] = new Finding('many_invalid', 'warn', $campaign, ['invalid_rate' => round(($row['leads'] - $row['valid']) / $leads * 100, 1), 'leads' => $row['leads']]);
            }

            if ($previous !== null && $previous - $score >= self::DROP_ALERT) {
                $drops[] = new Finding('quality_drop', 'warn', $campaign, ['quality' => $score, 'previous_quality' => $previous, 'leads' => $row['leads']]);
            }
        }

        return $drops;
    }

    /** @param  array<string, mixed>  $row  a CampaignMetrics row */
    public static function score(array $row): int
    {
        $n = max(1, $row['leads']);
        $rate = fn (string $key) => $row[$key] / $n;

        $points = 15 * $rate('valid')
            + 25 * $rate('engaged')
            + 35 * min(1, $rate('qualified') / 0.5)
            + 15 * min(1, $rate('appointments') / 0.2)
            + 10 * min(1, $rate('won') / 0.05);

        return (int) round(min(100, max(0, $points)));
    }
}
