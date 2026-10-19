<?php

namespace App\Domain\Campaigns;

use App\Models\Lead;
use App\Models\MetricSnapshot;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Platform → campaign → ad set → ad → creative, each with the whole chain:
 * spend, leads, valid, engaged, qualified, appointments, won, revenue, and
 * what each of those cost.
 *
 * The same predicates as every other report — so a creative's qualified count
 * and its campaign's add up — grouped at whichever level was asked for. Spend
 * comes from the platform's daily numbers at that level; the rest from leads.
 */
final class QualityBreakdown
{
    public const LEVELS = ['platform', 'campaign', 'ad_set', 'ad', 'creative'];

    public function __construct(private ?User $user, private CarbonInterface $from, private CarbonInterface $to) {}

    /** @return list<array<string, mixed>> */
    public function rows(string $level): array
    {
        [$leadKey, $leadLabel, $spendKey] = match ($level) {
            'platform' => ['campaigns.platform', 'campaigns.platform', 'metric_snapshots.platform'],
            'campaign' => ['campaigns.uuid', 'campaigns.name', 'campaigns.uuid'],
            'ad_set' => ['ad_sets.uuid', 'ad_sets.name', 'ad_sets.uuid'],
            'ad' => ['ads.uuid', 'ads.name', 'ads.uuid'],
            'creative' => ['creatives.uuid', 'coalesce(creatives.headline, creatives.name, ads.name)', 'creatives.uuid'],
        };

        $leads = Lead::query()
            ->when($this->user, fn ($q) => $q->visibleTo($this->user))
            ->reorder()
            ->join('campaigns', 'campaigns.id', '=', 'leads.campaign_ref_id')
            ->leftJoin('ad_sets', 'ad_sets.id', '=', 'leads.ad_set_ref_id')
            ->leftJoin('ads', 'ads.id', '=', 'leads.ad_ref_id')
            ->leftJoin('creatives', 'creatives.id', '=', 'ads.creative_id')
            ->whereBetween('leads.created_at', [$this->from->copy()->startOfDay(), $this->to->copy()->endOfDay()])
            ->whereNotNull(DB::raw($leadKey))
            ->selectRaw("{$leadKey} as k, min({$leadLabel}) as label, min(campaigns.platform) as platform,
                count(*) as leads,
                count(*) filter (where leads.status <> 'invalid') as valid,
                count(*) filter (where leads.first_response_at is not null or leads.status in ('replied', 'qualified', 'appointment', 'negotiation', 'won')) as engaged,
                count(*) filter (where leads.qualification_status = 'qualified' or leads.status in ('qualified', 'appointment', 'negotiation', 'won')) as qualified,
                count(*) filter (where leads.status in ('appointment', 'negotiation', 'won')) as appointments,
                count(*) filter (where leads.status = 'won') as won,
                coalesce(sum(leads.deal_value_minor), 0) as revenue_minor")
            ->groupBy(DB::raw($leadKey))
            ->get()->keyBy('k');

        $spend = MetricSnapshot::query()
            ->join('campaigns', 'campaigns.id', '=', 'metric_snapshots.campaign_id')
            ->leftJoin('ad_sets', 'ad_sets.id', '=', 'metric_snapshots.ad_set_id')
            ->leftJoin('ads', 'ads.id', '=', 'metric_snapshots.ad_id')
            ->leftJoin('creatives', 'creatives.id', '=', 'ads.creative_id')
            ->whereBetween('metric_snapshots.date', [$this->from->toDateString(), $this->to->toDateString()])
            ->whereNotNull(DB::raw($spendKey))
            ->selectRaw("{$spendKey} as k, sum(metric_snapshots.spend_minor) as spend, sum(metric_snapshots.clicks) as clicks, sum(metric_snapshots.impressions) as impressions")
            ->groupBy(DB::raw($spendKey))
            ->get()->keyBy('k');

        $keys = $leads->keys()->merge($spend->keys())->unique();

        return $keys->map(function ($k) use ($leads, $spend, $level) {
            $l = $leads->get($k);
            $s = $spend->get($k);
            $n = fn (string $f) => (int) ($l->{$f} ?? 0);
            $spendMinor = $s ? (int) $s->spend : null;
            $revenueMinor = $n('revenue_minor');
            $per = fn (int $d) => $spendMinor !== null && $d > 0 ? round($spendMinor / $d / 100, 2) : null;

            return [
                'key' => (string) $k,
                'label' => $level === 'platform' ? (string) $k : (string) ($l->label ?? $k),
                'platform' => $level === 'platform' ? (string) $k : ($l->platform ?? null),
                'spend' => $spendMinor !== null ? round($spendMinor / 100, 2) : null,
                'leads' => $n('leads'),
                'valid' => $n('valid'),
                'engaged' => $n('engaged'),
                'qualified' => $n('qualified'),
                'appointments' => $n('appointments'),
                'won' => $n('won'),
                'revenue' => round($revenueMinor / 100, 2),
                'cpl' => $per($n('leads')),
                'cpql' => $per($n('qualified')),
                // What one customer cost: spend over sales.
                'cac' => $per($n('won')),
                'roas' => $spendMinor && ($n('won') === 0 || $revenueMinor > 0) ? round($revenueMinor / $spendMinor, 2) : null,
                'qualified_rate' => $n('leads') > 0 ? round($n('qualified') / $n('leads') * 100, 1) : null,
                'won_rate' => $n('leads') > 0 ? round($n('won') / $n('leads') * 100, 1) : null,
            ];
        })->sortByDesc(fn ($r) => [$r['qualified'], $r['leads']])->values()->all();
    }
}
