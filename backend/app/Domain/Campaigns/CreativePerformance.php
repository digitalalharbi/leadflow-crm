<?php

namespace App\Domain\Campaigns;

use App\Domain\Agents\CreativeAgent;
use App\Models\Ad;
use App\Models\CreativeDraft;
use App\Models\Lead;
use App\Models\MetricSnapshot;
use Carbon\CarbonImmutable;

/**
 * Every ad, judged by what it brought: preview, platform, campaign, spend, CTR,
 * qualified leads, won leads, revenue, fatigue — and its drafts.
 *
 * Clicks are the platform's figure; qualified, won and revenue are ours. An ad
 * with a fine CTR and no qualified lead is the case this screen exists to show.
 */
final class CreativePerformance
{
    public function __construct(private CreativeAgent $creative) {}

    /** @return list<array<string, mixed>> */
    public function rows(int $days = 30, ?string $platform = null, ?int $campaignId = null): array
    {
        $to = CarbonImmutable::today();
        $from = $to->subDays($days - 1);

        $ads = Ad::query()->with(['creative', 'campaign:id,uuid,name,platform,currency,external_id', 'adSet:id,uuid,name,external_id'])
            ->whereNull('archived_at')
            ->when($campaignId, fn ($q) => $q->where('campaign_id', $campaignId))
            ->when($platform, fn ($q) => $q->whereHas('campaign', fn ($c) => $c->where('platform', $platform)))
            ->limit(300)->get();

        if ($ads->isEmpty()) {
            return [];
        }

        $ids = $ads->pluck('id');

        $platformNumbers = MetricSnapshot::query()->whereIn('ad_id', $ids)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('ad_id, sum(spend_minor) as spend, sum(impressions) as impressions, sum(clicks) as clicks')
            ->groupBy('ad_id')->get()->keyBy('ad_id');

        $crm = Lead::query()->whereIn('ad_ref_id', $ids)
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw("ad_ref_id, count(*) as leads,
                count(*) filter (where qualification_status = 'qualified' or status in ('qualified', 'appointment', 'negotiation', 'won')) as qualified,
                count(*) filter (where status = 'won') as won,
                coalesce(sum(deal_value_minor), 0) as revenue_minor")
            ->groupBy('ad_ref_id')->get()->keyBy('ad_ref_id');

        $fatigue = $this->creative->adStats($to);
        $drafts = CreativeDraft::query()->whereIn('ad_id', $ids)->where('status', 'draft')
            ->selectRaw('ad_id, count(*) as n')->groupBy('ad_id')->pluck('n', 'ad_id');

        return $ads->map(function (Ad $ad) use ($platformNumbers, $crm, $fatigue, $drafts) {
            $p = $platformNumbers->get($ad->id);
            $c = $crm->get($ad->id);
            $impressions = (int) ($p->impressions ?? 0);
            $spendMinor = $p ? (int) $p->spend : null;
            $qualified = (int) ($c->qualified ?? 0);

            return [
                'id' => $ad->uuid,
                'name' => $ad->name,
                'status' => $ad->status,
                'platform' => $ad->campaign?->platform,
                'campaign' => $ad->campaign ? ['id' => $ad->campaign->uuid, 'name' => $ad->campaign->name] : null,
                'ad_set' => $ad->adSet ? ['id' => $ad->adSet->uuid, 'name' => $ad->adSet->name] : null,
                'on_platform' => $ad->external_id !== null,
                'creative' => $ad->creative ? [
                    'external_id' => $ad->creative->external_id,
                    'format' => $ad->creative->format,
                    'headline' => $ad->creative->headline,
                    'body' => $ad->creative->body,
                    'thumbnail_url' => $ad->creative->thumbnail_url ?? $ad->creative->media_url,
                ] : null,
                'spend' => $spendMinor !== null ? round($spendMinor / 100, 2) : null,
                'impressions' => $p ? $impressions : null,
                'clicks' => $p ? (int) $p->clicks : null,
                'ctr' => $impressions > 0 ? round(((int) $p->clicks) / $impressions * 100, 2) : null,
                'leads' => (int) ($c->leads ?? 0),
                'qualified' => $qualified,
                'won' => (int) ($c->won ?? 0),
                'revenue' => round(((int) ($c->revenue_minor ?? 0)) / 100, 2),
                'cpql' => $spendMinor !== null && $qualified > 0 ? round($spendMinor / $qualified / 100, 2) : null,
                'fatigue' => isset($fatigue[$ad->id]) ? CreativeAgent::fatigue($fatigue[$ad->id]) : null,
                'drafts' => (int) ($drafts[$ad->id] ?? 0),
            ];
        })->sortByDesc(fn ($r) => [$r['qualified'], $r['leads']])->values()->all();
    }
}
