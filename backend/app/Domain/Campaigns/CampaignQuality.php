<?php

namespace App\Domain\Campaigns;

use App\Domain\Tracking\TrackingHealth;
use App\Models\Lead;
use Carbon\CarbonInterface;

/**
 * One number for "is this campaign any good", with the reason and what to do about it.
 *
 * A campaign is judged on ten things, in three groups:
 *
 *   what each step costs      cost per lead, per qualified lead, per interested lead, per visit,
 *                             per reservation, and per sale (CAC) — each against what the workspace
 *                             pays on average for the same step, because "good" is relative to what
 *                             the rest of the account achieves, not to a number somebody guessed
 *   what it returns           ROAS, and the quality of the people it brings
 *   whether we can believe it attribution (how many leads we can trace to it) and tracking health
 *                             (is the platform being told what happened)
 *
 * Each is scored 0–100; a figure the campaign has no data for is left out and the rest are weighed
 * again, so a young campaign is judged on what exists — and the result says how much of the picture
 * that was (`confidence`). The classes are the product's own words: Excellent, Healthy, Watch,
 * At Risk, Critical. The score names its two weakest components (why) and the one action that
 * would help most.
 */
class CampaignQuality
{
    public const CLASSES = ['excellent', 'healthy', 'watch', 'at_risk', 'critical'];

    /** @var array<string,int> component → weight (they add up to 100) */
    public const WEIGHTS = [
        'cpl' => 8, 'cpql' => 12, 'cost_per_interested' => 10, 'cost_per_visit' => 10, 'cost_per_reservation' => 10, 'cac' => 6,
        'roas' => 14, 'lead_quality' => 14, 'attribution' => 8, 'tracking' => 8,
    ];

    /** Cost components, scored against the account's own average for the same step. */
    private const COSTS = ['cpl', 'cpql', 'cost_per_interested', 'cost_per_visit', 'cost_per_reservation', 'cac'];

    /** @var array<string,string> component → the action that fixes it, a key in the `quality.action` catalogue */
    private const ACTION = [
        'cpl' => 'sharpen_targeting', 'cpql' => 'sharpen_targeting', 'cost_per_interested' => 'refresh_creative',
        'cost_per_visit' => 'follow_up_visits', 'cost_per_reservation' => 'follow_up_visits', 'cac' => 'cut_budget',
        'roas' => 'cut_budget', 'lead_quality' => 'tighten_audience', 'attribution' => 'add_tracking_links', 'tracking' => 'fix_tracking',
    ];

    /** @return array{score:?int,class:?string,confidence:string,components:list<array<string,mixed>>,why:list<array<string,mixed>>,action:?array<string,mixed>} */
    public static function score(array $row, array $benchmarks, ?int $attribution = null, ?int $tracking = null): array
    {
        $components = [];

        foreach (self::COSTS as $key) {
            $value = $row[$key] ?? null;
            $bench = $benchmarks[$key] ?? null;

            if ($value !== null && $bench !== null && $bench > 0) {
                $components[$key] = ['value' => (float) $value, 'benchmark' => (float) $bench, 'score' => self::costScore((float) $value / (float) $bench)];
            }
        }

        if (($row['roas'] ?? null) !== null && ($row['spend'] ?? 0) > 0) {
            $components['roas'] = ['value' => (float) $row['roas'], 'benchmark' => 1.0, 'score' => self::roasScore((float) $row['roas'])];
        }

        // How good the people are: the campaign's lead-quality score when one has been worked out, otherwise the share who qualified.
        $quality = $row['quality_score'] ?? (($row['leads'] ?? 0) >= 5 && isset($row['qualified']) ? min(100, (int) round($row['qualified'] / $row['leads'] * 200)) : null);

        if ($quality !== null) {
            $components['lead_quality'] = ['value' => (float) $quality, 'benchmark' => null, 'score' => (int) max(0, min(100, $quality))];
        }

        if ($attribution !== null) {
            $components['attribution'] = ['value' => (float) $attribution, 'benchmark' => null, 'score' => $attribution];
        }

        if ($tracking !== null) {
            $components['tracking'] = ['value' => (float) $tracking, 'benchmark' => null, 'score' => $tracking];
        }

        if ($components === [] || ($row['leads'] ?? 0) < 1) {
            return ['score' => null, 'class' => null, 'confidence' => 'none', 'components' => [], 'why' => [], 'action' => null];
        }

        $weight = array_sum(array_map(fn (string $k) => self::WEIGHTS[$k], array_keys($components)));
        $score = (int) round(array_sum(array_map(fn (string $k, array $c) => $c['score'] * self::WEIGHTS[$k], array_keys($components), $components)) / $weight);

        // The weakest components, weighted by how much they could move the total: the reason in two lines.
        $shortfall = collect($components)->map(fn (array $c, string $k) => ['key' => $k, 'gap' => (100 - $c['score']) * self::WEIGHTS[$k]] + $c)->sortByDesc('gap')->values();
        $why = $shortfall->filter(fn (array $c) => $c['score'] < 70)->take(2)->map(fn (array $c) => [
            'key' => $c['key'], 'score' => $c['score'], 'value' => round($c['value'], 2), 'benchmark' => $c['benchmark'] !== null ? round($c['benchmark'], 2) : null,
        ])->values()->all();

        return [
            'score' => $score,
            'class' => self::classify($score),
            'confidence' => $weight >= 70 ? 'high' : ($weight >= 40 ? 'medium' : 'low'),
            'components' => collect($components)->map(fn (array $c, string $k) => ['key' => $k, 'score' => $c['score'], 'weight' => self::WEIGHTS[$k]])->values()->all(),
            'why' => $why,
            'action' => $why === [] ? null : ['key' => self::ACTION[$why[0]['key']], 'component' => $why[0]['key']],
        ];
    }

    public static function classify(int $score): string
    {
        return match (true) {
            $score >= 85 => 'excellent',
            $score >= 70 => 'healthy',
            $score >= 55 => 'watch',
            $score >= 40 => 'at_risk',
            default => 'critical',
        };
    }

    /** What something costs against what the account pays on average: as expected is 70, half as much is 100, double is 10. */
    public static function costScore(float $ratio): int
    {
        return (int) round(max(0, min(100, 70 + (1 - $ratio) * 60)));
    }

    /** Break-even is 50; three times the spend back is 100; nothing back is 0. */
    public static function roasScore(float $roas): int
    {
        return (int) round($roas >= 1 ? min(100, 50 + ($roas - 1) * 25) : max(0, $roas * 50));
    }

    /**
     * Scores every row of a campaign list against the workspace's own averages for the same period.
     *
     * @param  list<array<string,mixed>>  $rows  CampaignMetrics::rows()
     * @param  array<string,mixed>  $totals  CampaignMetrics::totals()
     * @return list<array<string,mixed>> the rows with a `quality` entry
     */
    public static function attach(array $rows, array $totals, CarbonInterface $from, CarbonInterface $to): array
    {
        $benchmarks = array_intersect_key($totals, array_flip(self::COSTS));
        $attribution = self::attribution($rows, $from, $to);
        $tracking = self::tracking();

        return array_map(fn (array $row) => $row + ['quality' => self::score($row, $benchmarks, $attribution[$row['id']] ?? null, $tracking[$row['platform']] ?? null)], $rows);
    }

    /**
     * The share of each campaign's leads we can trace: it carries a click id, a tracking link, or UTM tags.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,int> campaign uuid → percent
     */
    public static function attribution(array $rows, CarbonInterface $from, CarbonInterface $to): array
    {
        if ($rows === []) {
            return [];
        }

        $counts = Lead::query()->reorder()->join('campaigns', 'campaigns.id', '=', 'leads.campaign_ref_id')
            ->whereIn('campaigns.uuid', array_column($rows, 'id'))
            ->whereBetween('leads.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('campaigns.uuid as id, count(*) as leads,
                count(*) filter (where leads.click_id is not null or leads.first_touch_link_id is not null or leads.utm_source is not null) as traced')
            ->groupBy('campaigns.uuid')->get();

        return $counts->filter(fn ($r) => (int) $r->leads >= 3)->mapWithKeys(fn ($r) => [$r->id => (int) round((int) $r->traced / (int) $r->leads * 100)])->all();
    }

    /**
     * How well each platform is being told what happened, 0–100, for the platforms that are connected.
     *
     * @return array<string,int> platform → score
     */
    public static function tracking(): array
    {
        $out = [];

        foreach (app(TrackingHealth::class)->build(7)['platforms'] as $p) {
            if (! $p['connected']) {
                continue;
            }

            $out[$p['platform']] = match ($p['status']) {
                'healthy' => 100,
                'attention' => 65,
                'risk' => 20,
                default => 45,
            };
        }

        return $out;
    }
}
