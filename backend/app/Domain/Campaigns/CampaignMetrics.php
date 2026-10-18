<?php

namespace App\Domain\Campaigns;

use App\Domain\Agents\CreativeAgent;
use App\Domain\Leads\LeadClassification;
use App\Enums\LeadStatus;
use App\Models\Campaign;
use App\Models\CampaignSpend;
use App\Models\ConversionExport;
use App\Models\Lead;
use App\Models\MetricSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What each campaign cost and what it brought, side by side.
 *
 * Two sources, never blended into one number without saying so:
 *
 *   platform   spend, impressions, clicks — read from the ad account
 *   CRM        leads, how many were real, engaged, qualified, booked, won, and
 *              what the won ones closed for — read from the leads themselves
 *
 * A campaign's cost per qualified lead is a platform number divided by a CRM
 * number, which is the whole reason this product exists: the platform can only
 * say how many forms were submitted.
 *
 * Spend falls back to what somebody typed in (campaign_spends) for campaigns no
 * ad account has reported on; the row says which it was.
 */
class CampaignMetrics
{
    /** No user means the workspace's own view — used by routines, which act for nobody in particular. */
    public function __construct(private ?User $user, private CarbonInterface $from, private CarbonInterface $to) {}

    /**
     * @param  array{platform?: string, project_id?: int, status?: string}  $filters
     * @return list<array<string, mixed>>
     */
    public function rows(array $filters = []): array
    {
        $campaigns = Campaign::query()
            ->with(['project:id,uuid,name', 'account:id,uuid,name'])
            ->whereNull('archived_at')
            ->when($filters['platform'] ?? null, fn ($q, $p) => $q->where('platform', $p))
            ->when($filters['project_id'] ?? null, fn ($q, $id) => $q->where('project_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->get();

        return $this->rowsFor($campaigns);
    }

    /** @return list<array<string, mixed>> */
    private function rowsFor($campaigns): array
    {
        if ($campaigns->isEmpty()) {
            return [];
        }

        $campaigns->loadMissing(['project:id,uuid,name', 'account:id,uuid,name']);
        $platform = $this->platformNumbers();
        $crm = $this->crmNumbers();
        $manual = $this->manualSpend($campaigns);

        return $campaigns->map(function (Campaign $campaign) use ($platform, $crm, $manual) {
            $p = $platform[$campaign->id] ?? null;
            $c = $crm[$campaign->id] ?? $this->emptyCrm();

            $spendMinor = $p['spend_minor'] ?? null;
            $spendSource = $spendMinor !== null && $spendMinor > 0 ? 'platform' : null;

            if ($spendSource === null && isset($manual["{$campaign->platform}|{$campaign->name}"])) {
                $spendMinor = $manual["{$campaign->platform}|{$campaign->name}"];
                $spendSource = 'manual';
            }

            return array_merge([
                'id' => $campaign->uuid,
                'name' => $campaign->name,
                'platform' => $campaign->platform,
                'status' => $campaign->status,
                'origin' => $campaign->origin,
                'objective' => $campaign->objective,
                'project' => $campaign->project ? ['id' => $campaign->project->uuid, 'name' => $campaign->project->name] : null,
                'account' => $campaign->account?->name,
                'daily_budget' => $campaign->daily_budget_minor !== null ? round($campaign->daily_budget_minor / 100, 2) : null,
                'currency' => $campaign->currency,
                'last_synced_at' => $campaign->last_synced_at?->toIso8601String(),
                'quality_score' => $campaign->quality_score,
                'quality_previous' => $campaign->quality_previous,
                'spend_source' => $spendSource,
                'impressions' => $p['impressions'] ?? null,
                'clicks' => $p['clicks'] ?? null,
                'ctr' => ($p['impressions'] ?? 0) > 0 ? round($p['clicks'] / $p['impressions'] * 100, 2) : null,
                'daily' => $p['daily'] ?? [],
            ], array_diff_key($c, ['_revenue_minor' => 1]), $this->costs($spendMinor, $c));
        })->sortByDesc(fn (array $row) => [$row['spend'] ?? 0, $row['leads']])->values()->all();
    }

    /** @param  list<array<string, mixed>>  $rows */
    public function totals(array $rows): array
    {
        $sum = fn (string $key) => array_sum(array_map(fn ($r) => $r[$key] ?? 0, $rows));
        $currencies = array_values(array_unique(array_filter(array_column($rows, 'currency'))));

        $spend = collect($rows)->filter(fn ($r) => $r['spend'] !== null)->sum('spend');
        $any = collect($rows)->contains(fn ($r) => $r['spend'] !== null);
        $leads = $sum('leads');
        $qualified = $sum('qualified');
        $won = $sum('won');
        $revenue = $sum('revenue');

        return [
            'campaigns' => count($rows),
            'spend' => $any ? round($spend, 2) : null,
            'leads' => $leads,
            'valid' => $sum('valid'),
            'engaged' => $sum('engaged'),
            'qualified' => $qualified,
            'appointments' => $sum('appointments'),
            'won' => $won,
            'revenue' => round($revenue, 2),
            'cpl' => $any && $leads > 0 ? round($spend / $leads, 2) : null,
            'cpql' => $any && $qualified > 0 ? round($spend / $qualified, 2) : null,
            'cpa' => $any && $won > 0 ? round($spend / $won, 2) : null,
            'roas' => $any && $spend > 0 && ($won === 0 || $revenue > 0) ? round($revenue / $spend, 2) : null,
            'qualified_rate' => $leads > 0 ? round($qualified / $leads * 100, 1) : null,
            'won_rate' => $leads > 0 ? round($won / $leads * 100, 1) : null,
            // Different currencies are never added together: a total across them
            // would be a number in no currency at all.
            'mixed_currencies' => count($currencies) > 1,
        ];
    }

    /**
     * One campaign opened up: its row, its ads with what each cost and brought,
     * the day-by-day spend and leads, and the quality of what arrived.
     *
     * @return array<string, mixed>
     */
    public function detail(Campaign $campaign): array
    {
        $row = collect($this->rowsFor(new Collection([$campaign])))->first();

        $adSpend = MetricSnapshot::query()
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('ad_id')
            ->whereBetween('date', [$this->from->toDateString(), $this->to->toDateString()])
            ->selectRaw('ad_id, sum(spend_minor) as spend, sum(impressions) as impressions, sum(clicks) as clicks')
            ->groupBy('ad_id')
            ->get()
            ->keyBy('ad_id');

        $adCrm = $this->crmQuery()->where('campaign_ref_id', $campaign->id)
            ->whereNotNull('ad_ref_id')
            ->addSelect('ad_ref_id')
            ->groupBy('ad_ref_id')
            ->get()
            ->keyBy('ad_ref_id');

        $creative = app(CreativeAgent::class)->adStats(CarbonImmutable::parse($this->to->toDateString()), $campaign->id);

        $ads = $campaign->ads()->with(['creative', 'adSet:id,name'])->whereNull('archived_at')->get()
            ->map(function ($ad) use ($adSpend, $adCrm, $creative) {
                $p = $adSpend->get($ad->id);
                $c = $adCrm->get($ad->id);
                $crm = $c ? $this->crmRow($c) : $this->emptyCrm();
                $spend = $p ? (int) $p->spend : null;
                $impressions = $p ? (int) $p->impressions : 0;

                return array_merge([
                    'id' => $ad->uuid,
                    'name' => $ad->name,
                    'status' => $ad->status,
                    'ad_set' => $ad->adSet?->name,
                    'fatigue' => isset($creative[$ad->id]) ? CreativeAgent::fatigue($creative[$ad->id]) : null,
                    'impressions' => $p ? $impressions : null,
                    'clicks' => $p ? (int) $p->clicks : null,
                    'ctr' => $impressions > 0 ? round(((int) $p->clicks) / $impressions * 100, 2) : null,
                    'creative' => $ad->creative ? [
                        'format' => $ad->creative->format,
                        'headline' => $ad->creative->headline,
                        'body' => $ad->creative->body,
                        'thumbnail_url' => $ad->creative->thumbnail_url ?? $ad->creative->media_url,
                    ] : null,
                ], array_diff_key($crm, ['_revenue_minor' => 1]), $this->costs($spend, $crm));
            })
            ->sortByDesc('qualified')->values()->all();

        $leadsByDay = $this->leads()
            ->where('campaign_ref_id', $campaign->id)
            ->whereBetween('leads.created_at', [$this->from->copy()->startOfDay(), $this->to->copy()->endOfDay()])
            ->selectRaw('leads.created_at::date as day, count(*) as total')
            ->groupBy(DB::raw('1'))
            ->pluck('total', 'day');

        $classes = $this->leads()
            ->where('campaign_ref_id', $campaign->id)
            ->whereBetween('leads.created_at', [$this->from->copy()->startOfDay(), $this->to->copy()->endOfDay()])
            ->selectRaw(LeadClassification::sql('leads').' as class, count(*) as total')
            ->groupBy(DB::raw('1'))
            ->pluck('total', 'class');

        /*
         * What has been told back to the platform about this campaign's leads —
         * the loop that lets its algorithm learn from customers, not forms.
         */
        $feedback = ConversionExport::query()
            ->whereIn('lead_id', Lead::query()->where('campaign_ref_id', $campaign->id)->select('id'))
            ->selectRaw('event, status, count(*) as total')
            ->groupBy('event', 'status')
            ->get()
            ->groupBy('event')
            ->map(fn ($rows) => $rows->pluck('total', 'status')->map(fn ($n) => (int) $n))
            ->all();

        return [
            'campaign' => $row,
            'feedback' => collect(['qualified', 'appointment', 'won'])->mapWithKeys(fn ($e) => [$e => $feedback[$e] ?? (object) []])->all(),
            'ads' => $ads,
            'leads_daily' => $leadsByDay->map(fn ($n, $day) => ['date' => (string) $day, 'count' => (int) $n])->values()->all(),
            'classification' => collect(LeadClassification::values())->map(fn ($k) => ['key' => $k, 'count' => (int) ($classes[$k] ?? 0)])->all(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function platformNumbers(): array
    {
        $rows = MetricSnapshot::query()
            ->whereBetween('date', [$this->from->toDateString(), $this->to->toDateString()])
            ->selectRaw('campaign_id, date, sum(spend_minor) as spend, sum(impressions) as impressions, sum(clicks) as clicks')
            ->groupBy('campaign_id', 'date')
            ->orderBy('date')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $o = &$out[$row->campaign_id];
            $o['spend_minor'] = ($o['spend_minor'] ?? 0) + (int) $row->spend;
            $o['impressions'] = ($o['impressions'] ?? 0) + (int) $row->impressions;
            $o['clicks'] = ($o['clicks'] ?? 0) + (int) $row->clicks;
            $o['daily'][] = ['date' => $row->date->toDateString(), 'spend' => round(((int) $row->spend) / 100, 2)];
            unset($o);
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private function crmNumbers(): array
    {
        $won = LeadStatus::Won->value;
        $invalid = LeadStatus::Invalid->value;
        $replied = "'replied', 'qualified', 'appointment', 'negotiation', '{$won}'";

        $rows = $this->crmQuery()->whereNotNull('campaign_ref_id')
            ->addSelect('campaign_ref_id')
            ->groupBy('campaign_ref_id')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[$row->campaign_ref_id] = $this->crmRow($row);
        }

        return $out;
    }

    /** The CRM funnel as aggregate columns, for whatever the caller groups by. */
    private function crmQuery()
    {
        $won = LeadStatus::Won->value;
        $invalid = LeadStatus::Invalid->value;
        $replied = "'replied', 'qualified', 'appointment', 'negotiation', '{$won}'";

        return $this->leads()
            ->whereBetween('leads.created_at', [$this->from->copy()->startOfDay(), $this->to->copy()->endOfDay()])
            ->selectRaw(sprintf(
                "count(*) as leads,
                 count(*) filter (where status <> '%1\$s') as valid,
                 count(*) filter (where first_response_at is not null or status in (%2\$s)) as engaged,
                 count(*) filter (where qualification_status = 'qualified' or status in ('qualified', 'appointment', 'negotiation', '%3\$s')) as qualified,
                 count(*) filter (where status in ('appointment', 'negotiation', '%3\$s')) as appointments,
                 count(*) filter (where status = '%3\$s') as won,
                 count(*) filter (where (%4\$s) in ('cold', 'new')) as cold,
                 count(*) filter (where (%4\$s) in ('hot', 'priority')) as hot,
                 coalesce(sum(deal_value_minor), 0) as revenue_minor",
                $invalid, $replied, $won, LeadClassification::sql('leads'),
            ));
    }

    private function crmRow(object $row): array
    {
        return [
            'leads' => (int) $row->leads,
            'valid' => (int) $row->valid,
            'engaged' => (int) $row->engaged,
            'qualified' => (int) $row->qualified,
            'appointments' => (int) $row->appointments,
            'won' => (int) $row->won,
            'cold' => (int) $row->cold,
            'hot' => (int) $row->hot,
            'revenue' => round(((int) $row->revenue_minor) / 100, 2),
            '_revenue_minor' => (int) $row->revenue_minor,
        ];
    }

    private function leads()
    {
        $query = Lead::query();

        return ($this->user ? $query->visibleTo($this->user) : $query)->reorder();
    }

    private function emptyCrm(): array
    {
        return ['leads' => 0, 'valid' => 0, 'engaged' => 0, 'qualified' => 0, 'appointments' => 0, 'won' => 0, 'cold' => 0, 'hot' => 0, 'revenue' => 0.0, '_revenue_minor' => 0];
    }

    /** @return array<string, int> minor units keyed "platform|name" */
    private function manualSpend($campaigns): array
    {
        return CampaignSpend::query()
            ->whereBetween('spend_date', [$this->from->toDateString(), $this->to->toDateString()])
            ->whereIn('campaign_name', $campaigns->pluck('name')->unique()->all())
            ->selectRaw('platform, campaign_name, sum(amount_minor) as total')
            ->groupBy('platform', 'campaign_name')
            ->get()
            ->mapWithKeys(fn ($row) => ["{$row->platform}|{$row->campaign_name}" => (int) $row->total])
            ->all();
    }

    /**
     * Null rather than zero whenever a number cannot be computed: a campaign
     * nobody costed is not free, and one whose sales nobody valued does not
     * return nothing.
     *
     * @return array<string, mixed>
     */
    private function costs(?int $spendMinor, array $crm): array
    {
        $per = fn (int $divisor) => $spendMinor !== null && $divisor > 0 ? round($spendMinor / $divisor / 100, 2) : null;
        $share = fn (int $part) => $crm['leads'] > 0 ? round($part / $crm['leads'] * 100, 1) : null;

        return [
            'spend' => $spendMinor !== null ? round($spendMinor / 100, 2) : null,
            'cpl' => $per($crm['leads']),
            // Null, not the whole spend, when nothing qualified: the cost of
            // nothing is the finding itself, and the leads column says so.
            'cpql' => $per($crm['qualified']),
            'cost_per_appointment' => $per($crm['appointments']),
            'cpa' => $per($crm['won']),
            'roas' => $spendMinor !== null && $spendMinor > 0 && ($crm['won'] === 0 || $crm['_revenue_minor'] > 0)
                ? round($crm['_revenue_minor'] / $spendMinor, 2)
                : null,
            'revenue_per_lead' => $crm['leads'] > 0 && $crm['_revenue_minor'] > 0 ? round($crm['_revenue_minor'] / $crm['leads'] / 100, 2) : null,
            'qualified_rate' => $share($crm['qualified']),
            'won_rate' => $share($crm['won']),
            'cold_rate' => $share($crm['cold']),
        ];
    }
}
