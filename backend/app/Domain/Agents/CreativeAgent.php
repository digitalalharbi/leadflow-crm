<?php

namespace App\Domain\Agents;

use App\Models\Ad;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\MetricSnapshot;
use Carbon\CarbonImmutable;

/**
 * Looks at the ads themselves: which are tiring, which never worked, which one
 * is carrying the campaign.
 *
 *   fatigue   click-through over the last 3 days against the 7 before, on at
 *             least 1,000 recent impressions; the score is how far it fell
 *   weak      over 14 days, a CTR under 60% of its own campaign's, on at least
 *             2,000 impressions — the creative, not the audience, is the issue
 *   winner    in each campaign, the ad with the best qualified rate on at least
 *             five leads — the one a refresh should learn from
 *
 * It reads and reports. Writing new copy or replacing a creative is for a
 * person, through a proposal; this agent does not touch an ad.
 */
class CreativeAgent
{
    /** @return list<Finding> */
    public function review(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $findings = [];

        $campaigns = Campaign::query()->whereNull('archived_at')->get()->keyBy('id');
        $stats = $this->adStats($today);
        $quality = $this->adQuality($today);
        $ads = Ad::query()->whereIn('id', array_unique(array_merge(array_keys($stats), array_keys($quality))))->get()->keyBy('id');

        $campaignCtr = [];
        foreach ($stats as $adId => $s) {
            $c = $ads[$adId]?->campaign_id;
            if ($c === null) {
                continue;
            }
            $campaignCtr[$c]['imp'] = ($campaignCtr[$c]['imp'] ?? 0) + $s['imp14'];
            $campaignCtr[$c]['clk'] = ($campaignCtr[$c]['clk'] ?? 0) + $s['clk14'];
        }

        foreach ($stats as $adId => $s) {
            $ad = $ads[$adId] ?? null;
            $campaign = $ad ? ($campaigns[$ad->campaign_id] ?? null) : null;
            if ($campaign === null) {
                continue;
            }

            $fatigue = self::fatigue($s);
            if ($fatigue !== null && $fatigue >= 30) {
                $findings[] = new Finding('fatigue', $fatigue >= 50 ? 'bad' : 'warn', $campaign, ['ad' => $ad->name, 'ad_id' => $ad->uuid, 'fatigue' => $fatigue]);
            }

            $cc = $campaignCtr[$campaign->id] ?? null;
            if ($s['imp14'] >= 2000 && $cc && $cc['imp'] > 0) {
                $ctr = $s['clk14'] / $s['imp14'] * 100;
                $base = $cc['clk'] / $cc['imp'] * 100;
                if ($base > 0 && $ctr < $base * 0.6) {
                    $findings[] = new Finding('weak_creative', 'warn', $campaign, ['ad' => $ad->name, 'ad_id' => $ad->uuid, 'ctr' => round($ctr, 2), 'campaign_ctr' => round($base, 2)]);
                }
            }
        }

        $best = [];
        foreach ($quality as $adId => $q) {
            $ad = $ads[$adId] ?? null;
            if ($ad === null || $q['leads'] < 5) {
                continue;
            }
            $rate = $q['qualified'] / $q['leads'];
            if (! isset($best[$ad->campaign_id]) || $rate > $best[$ad->campaign_id][1]) {
                $best[$ad->campaign_id] = [$ad, $rate, $q];
            }
        }

        foreach ($best as $campaignId => [$ad, $rate, $q]) {
            if ($rate > 0 && isset($campaigns[$campaignId])) {
                $findings[] = new Finding('winner_creative', 'good', $campaigns[$campaignId], ['ad' => $ad->name, 'ad_id' => $ad->uuid, 'qualified_rate' => round($rate * 100, 1), 'leads' => $q['leads']]);
            }
        }

        return $findings;
    }

    /** 0–100: how far click-through fell, or null when there is too little to say. */
    public static function fatigue(array $s): ?int
    {
        if ($s['imp3'] < 1000 || $s['imp7'] <= 0) {
            return null;
        }

        $recent = $s['clk3'] / $s['imp3'];
        $before = $s['clk7'] / $s['imp7'];

        if ($before <= 0) {
            return null;
        }

        return (int) round(max(0, min(1, 1 - $recent / $before)) * 100);
    }

    /** @return array<int, array{imp3: int, clk3: int, imp7: int, clk7: int, imp14: int, clk14: int}> */
    public function adStats(CarbonImmutable $today, ?int $campaignId = null): array
    {
        $recent = $today->subDays(2)->toDateString();
        $base = $today->subDays(9)->toDateString();

        return MetricSnapshot::query()
            ->whereNotNull('ad_id')
            ->when($campaignId, fn ($q) => $q->where('campaign_id', $campaignId))
            ->whereBetween('date', [$today->subDays(13)->toDateString(), $today->toDateString()])
            ->selectRaw('ad_id,
                coalesce(sum(impressions) filter (where date >= ?), 0) as imp3, coalesce(sum(clicks) filter (where date >= ?), 0) as clk3,
                coalesce(sum(impressions) filter (where date >= ? and date < ?), 0) as imp7, coalesce(sum(clicks) filter (where date >= ? and date < ?), 0) as clk7,
                coalesce(sum(impressions), 0) as imp14, coalesce(sum(clicks), 0) as clk14', [$recent, $recent, $base, $recent, $base, $recent])
            ->groupBy('ad_id')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->ad_id => [
                'imp3' => (int) $r->imp3, 'clk3' => (int) $r->clk3, 'imp7' => (int) $r->imp7,
                'clk7' => (int) $r->clk7, 'imp14' => (int) $r->imp14, 'clk14' => (int) $r->clk14,
            ]])
            ->all();
    }

    /** @return array<int, array{leads: int, qualified: int}> */
    private function adQuality(CarbonImmutable $today): array
    {
        return Lead::query()
            ->whereNotNull('ad_ref_id')
            ->where('created_at', '>=', $today->subDays(29)->startOfDay())
            ->selectRaw("ad_ref_id, count(*) as leads,
                count(*) filter (where qualification_status = 'qualified' or status in ('qualified', 'appointment', 'negotiation', 'won')) as qualified")
            ->groupBy('ad_ref_id')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->ad_ref_id => ['leads' => (int) $r->leads, 'qualified' => (int) $r->qualified]])
            ->all();
    }
}
