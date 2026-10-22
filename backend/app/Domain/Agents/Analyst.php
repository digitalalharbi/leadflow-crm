<?php

namespace App\Domain\Agents;

use App\Domain\Campaigns\CampaignMetrics;
use App\Models\Campaign;
use App\Models\ClientRule;
use App\Models\MetricSnapshot;
use Carbon\CarbonImmutable;

/**
 * Reads the numbers against what the client asked for, and says what it sees.
 *
 * Read-only by construction: it has no gateway and no adapter. Every threshold
 * is stated here and every finding carries the figures that triggered it, so a
 * person reading "over target" can check the arithmetic in the same breath.
 *
 * Nothing is judged before it has spent enough to judge — the client's minimum
 * spend, or failing that ten leads — because a campaign three hours old with no
 * sale has not failed, it has not started.
 */
class Analyst
{
    public const MIN_LEADS = 10;

    /** @return list<Finding> */
    public function analyze(?int $projectId = null, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $current = new CampaignMetrics(null, $today->subDays(6), $today);
        $previous = new CampaignMetrics(null, $today->subDays(13), $today->subDays(7));

        $now = collect($current->rows(['project_id' => $projectId]))->keyBy('id');
        $before = collect($previous->rows(['project_id' => $projectId]))->keyBy('id');
        $campaigns = Campaign::query()->whereIn('uuid', $now->keys())->get()->keyBy('uuid');
        $ctr = $this->ctrTrend($campaigns->pluck('id')->all(), $today);

        $findings = [];

        foreach ($now as $uuid => $row) {
            $campaign = $campaigns[$uuid];
            $rules = ClientRule::for($campaign->project_id);
            $spendMinor = $row['spend'] !== null ? (int) round($row['spend'] * 100) : null;
            $judged = $spendMinor !== null && $spendMinor > 0
                && ($rules->min_spend_to_judge_minor ? $spendMinor >= $rules->min_spend_to_judge_minor : $row['leads'] >= self::MIN_LEADS);

            $fig = fn (array $extra = []) => array_merge([
                'spend' => $row['spend'], 'leads' => $row['leads'], 'qualified' => $row['qualified'],
                'cpl' => $row['cpl'], 'cpql' => $row['cpql'], 'qualified_rate' => $row['qualified_rate'],
            ], $extra);

            if ($judged && $row['qualified'] === 0) {
                $findings[] = new Finding('no_results', 'bad', $campaign, $fig());
            } elseif ($judged && $rules->target_cpql_minor && $row['cpql'] !== null) {
                $target = $rules->target_cpql_minor / 100;

                if ($row['cpql'] > $target * 1.25) {
                    $findings[] = new Finding('over_target', $row['cpql'] > $target * 2 ? 'bad' : 'warn', $campaign, $fig(['target_cpql' => $target]));
                } elseif ($row['cpql'] <= $target * 0.75 && $row['qualified'] >= 5) {
                    $findings[] = new Finding('outperforming', 'good', $campaign, $fig(['target_cpql' => $target]));
                }
            }

            if ($judged && $rules->target_cpl_minor && $row['cpl'] !== null && $row['cpl'] > ($rules->target_cpl_minor / 100) * 1.25) {
                $findings[] = new Finding('cpl_over_target', 'warn', $campaign, $fig(['target_cpl' => $rules->target_cpl_minor / 100]));
            }

            $prev = $before->get($uuid);
            if ($prev && $prev['leads'] >= self::MIN_LEADS && $row['leads'] >= self::MIN_LEADS
                && $prev['qualified_rate'] !== null && $row['qualified_rate'] !== null
                && $prev['qualified_rate'] >= 10 && $row['qualified_rate'] < $prev['qualified_rate'] * 0.5) {
                $findings[] = new Finding('quality_drop', 'warn', $campaign, $fig(['previous_rate' => $prev['qualified_rate']]));
            }

            foreach ($this->spendGuard($campaign, $row, $rules, $today) as $finding) {
                $findings[] = $finding;
            }

            if (isset($ctr[$campaign->id])) {
                [$recent, $base, $impressions] = $ctr[$campaign->id];
                if ($impressions >= 1000 && $base > 0 && $recent < $base * 0.7) {
                    $findings[] = new Finding('fatigue', 'warn', $campaign, $fig(['ctr_recent' => round($recent, 2), 'ctr_before' => round($base, 2)]));
                }
            }
        }

        return $findings;
    }

    /** @return list<Finding> */
    public function guard(?int $projectId = null, ?CarbonImmutable $today = null): array
    {
        return array_values(array_filter(
            $this->analyze($projectId, $today),
            fn (Finding $f) => in_array($f->key, ['spend_spike', 'over_ceiling'], true),
        ));
    }

    /** @return list<Finding> */
    private function spendGuard(Campaign $campaign, array $row, ClientRule $rules, CarbonImmutable $today): array
    {
        $out = [];

        if ($rules->daily_budget_ceiling_minor && $campaign->daily_budget_minor && $campaign->daily_budget_minor > $rules->daily_budget_ceiling_minor) {
            $out[] = new Finding('over_ceiling', 'bad', $campaign, [
                'daily_budget' => $campaign->daily_budget_minor / 100, 'ceiling' => $rules->daily_budget_ceiling_minor / 100,
            ]);
        }

        $daily = collect($row['daily'])->keyBy('date');
        $yesterday = (float) ($daily[$today->subDay()->toDateString()]['spend'] ?? 0);
        $before = collect(range(2, 7))->map(fn ($d) => (float) ($daily[$today->subDays($d)->toDateString()]['spend'] ?? 0));
        $average = $before->avg();

        if ($average > 0 && $yesterday > $average * 2) {
            $out[] = new Finding('spend_spike', 'warn', $campaign, ['yesterday' => round($yesterday, 2), 'average' => round($average, 2)]);
        }

        return $out;
    }

    /** @return array<int, array{0: float, 1: float, 2: int}> campaign id => [ctr last 3 days, ctr 7 days before, impressions last 3 days] */
    private function ctrTrend(array $campaignIds, CarbonImmutable $today): array
    {
        if ($campaignIds === []) {
            return [];
        }

        $rows = MetricSnapshot::query()
            ->whereIn('campaign_id', $campaignIds)
            ->whereBetween('date', [$today->subDays(9)->toDateString(), $today->toDateString()])
            ->selectRaw('campaign_id,
                sum(impressions) filter (where date >= ?) as imp_recent, sum(clicks) filter (where date >= ?) as clk_recent,
                sum(impressions) filter (where date < ?) as imp_base, sum(clicks) filter (where date < ?) as clk_base', array_fill(0, 4, $today->subDays(2)->toDateString()))
            ->groupBy('campaign_id')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $recent = (int) $r->imp_recent > 0 ? (int) $r->clk_recent / (int) $r->imp_recent * 100 : 0.0;
            $base = (int) $r->imp_base > 0 ? (int) $r->clk_base / (int) $r->imp_base * 100 : 0.0;
            $out[$r->campaign_id] = [$recent, $base, (int) $r->imp_recent];
        }

        return $out;
    }
}
