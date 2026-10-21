<?php

namespace App\Domain\Analytics;

use App\Domain\Access\Permissions;
use App\Domain\Campaigns\CampaignMetrics;
use App\Domain\Campaigns\CampaignQuality;
use App\Domain\Partners\PartnerStats;
use App\Domain\Sales\RepPerformance;
use App\Domain\Tracking\TrackingHealth;
use App\Models\AutomationActionLog;
use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\Partner;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The operations home, second half: what is healthy and what is leading, as pictures.
 *
 * The first half is OpsPulse (today in nine numbers, four lights). This is the rest of what a
 * manager opens the product for — campaign health, tracking health, automation health, who on the
 * sales team is doing well for the leads they were given, the revenue trend, and the best source,
 * campaign and partner. Each part is for the people who may see it: a rep sees their own
 * revenue trend and the best source, not the team's ranking or the machinery.
 */
class OperationsHome
{
    /** @return array<string,mixed> */
    public function for(User $user): array
    {
        $team = $user->seesAllLeads() && ! $user->isExternal();
        $to = CarbonImmutable::today()->endOfDay();
        $from = $to->subDays(29)->startOfDay();

        return [
            'campaign_health' => $team && Permissions::has($user, 'campaigns.view') ? $this->campaignHealth($user, $from, $to) : null,
            'tracking_health' => $team && Permissions::has($user, 'tracking.view') ? $this->trackingHealth() : null,
            'automation_health' => $team && Permissions::has($user, 'automation.view') ? $this->automationHealth($from) : null,
            'sales_ranking' => $team && Permissions::has($user, 'analytics.view') ? $this->salesRanking($from, $to) : null,
            'revenue_trend' => Permissions::has($user, 'revenue.view') ? $this->revenueTrend($user, $from, $to) : null,
            'tops' => $this->tops($user, $from, $to, $team),
        ];
    }

    /** @return array{classes:array<string,int>,worst:list<array<string,mixed>>} */
    private function campaignHealth(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $metrics = new CampaignMetrics($user, $from, $to);
        $rows = $metrics->rows();
        $scored = CampaignQuality::attach($rows, $metrics->totals($rows), $from, $to);
        $ranked = collect($scored)->filter(fn (array $r) => $r['quality']['score'] !== null);

        return [
            'classes' => array_count_values($ranked->pluck('quality.class')->all()),
            'total' => count($rows),
            // The three that most need a look: the lowest scores among those with real spend or leads behind them.
            'worst' => $ranked->filter(fn (array $r) => $r['leads'] >= 3)->sortBy('quality.score')->take(3)->map(fn (array $r) => [
                'id' => $r['id'], 'name' => $r['name'], 'platform' => $r['platform'], 'score' => $r['quality']['score'], 'class' => $r['quality']['class'],
                'action' => $r['quality']['action']['key'] ?? null,
            ])->values()->all(),
        ];
    }

    /** @return array{platforms:list<array<string,mixed>>,counts:array<string,int>} */
    private function trackingHealth(): array
    {
        $platforms = collect(app(TrackingHealth::class)->build(7)['platforms'])->filter(fn ($p) => $p['connected'] || $p['events'] > 0);

        return [
            'platforms' => $platforms->map(fn ($p) => ['platform' => $p['platform'], 'status' => $p['status'], 'connected' => $p['connected'], 'success_rate' => $p['success_rate']])->values()->all(),
            'counts' => array_count_values($platforms->pluck('status')->all()),
        ];
    }

    /** @return array<string,mixed> */
    private function automationHealth(CarbonImmutable $since): array
    {
        $sent = LeadMessage::query()->reorder()->where('direction', 'outbound')->where('created_at', '>=', $since)
            ->selectRaw('count(*) filter (where user_id is null) as automated, count(*) filter (where user_id is not null) as human')->first();
        $total = (int) $sent->automated + (int) $sent->human;
        $day = now()->subDay();

        return [
            'handled_pct' => $total > 0 ? round((int) $sent->automated / $total * 100, 1) : null,
            'failed' => AutomationActionLog::query()->where('result', AutomationActionLog::FAILED)->where('happened_at', '>=', $day)->count(),
            'needs_human' => Lead::query()->reorder()->whereNotNull('leads.needs_human_reason')->whereNotIn('leads.status', ['won', 'lost'])->count(),
            'rules_active' => AutomationRule::query()->where('is_enabled', true)->count(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function salesRanking(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return collect((new RepPerformance)->build($from, $to, null, false)['reps'])->take(5)->map(fn (array $r) => [
            'id' => $r['id'], 'name' => $r['name'], 'leads' => $r['leads'], 'won' => $r['won'], 'won_vs_expected' => $r['won_vs_expected'], 'small_sample' => $r['small_sample'],
        ])->all();
    }

    /**
     * Won revenue by the day it closed, the last thirty days, and the thirty before for the arrow.
     *
     * @return array{days:list<array{date:string,revenue:float}>,total:float,previous_total:float}
     */
    private function revenueTrend(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $won = fn (CarbonImmutable $a, CarbonImmutable $b) => Lead::query()->visibleTo($user)->reorder()->where('leads.status', 'won')->whereBetween('leads.won_at', [$a, $b]);

        $byDay = $won($from, $to)->selectRaw('leads.won_at::date as d, coalesce(sum(leads.deal_value_minor), 0) as v')->groupBy('d')->pluck('v', 'd');

        return [
            'days' => collect(range(0, 29))->map(fn (int $i) => $from->addDays($i)->toDateString())->map(fn (string $d) => ['date' => $d, 'revenue' => round(((int) ($byDay[$d] ?? 0)) / 100, 2)])->all(),
            'total' => round(((int) $won($from, $to)->sum('leads.deal_value_minor')) / 100, 2),
            'previous_total' => round(((int) $won($from->subDays(30), $from->subDay()->endOfDay())->sum('leads.deal_value_minor')) / 100, 2),
        ];
    }

    /** The best source, campaign and partner of the month — each only for those who may see that kind of thing. @return array<string,mixed> */
    private function tops(User $user, CarbonImmutable $from, CarbonImmutable $to, bool $team): array
    {
        $leads = fn () => Lead::query()->visibleTo($user)->reorder()->whereBetween('leads.created_at', [$from, $to]);

        $source = $leads()->whereNotNull('leads.platform')->selectRaw("leads.platform, count(*) as leads, count(*) filter (where leads.status = 'won') as won")
            ->groupBy('leads.platform')->orderByDesc('leads')->orderByDesc('won')->first();
        $total = (int) $leads()->count();

        $campaign = null;

        if ($team && Permissions::has($user, 'campaigns.view')) {
            $best = collect((new CampaignMetrics($user, $from, $to))->rows())->filter(fn (array $r) => $r['leads'] > 0)->sortByDesc(fn (array $r) => [$r['reservations'], $r['won'], $r['leads']])->first();
            $campaign = $best ? ['id' => $best['id'], 'name' => $best['name'], 'platform' => $best['platform'], 'reservations' => $best['reservations'], 'won' => $best['won'], 'leads' => $best['leads']] : null;
        }

        $partner = null;

        if ($team && Permissions::has($user, 'partners.view')) {
            $ids = Partner::query()->where('is_active', true)->pluck('id')->all();
            $stats = (new PartnerStats)->for($ids, $from);
            $bestId = collect($ids)->filter(fn (int $id) => $stats[$id]['leads'] > 0)->sortByDesc(fn (int $id) => [$stats[$id]['won'], $stats[$id]['leads']])->first();
            $row = $bestId ? Partner::find($bestId) : null;
            $partner = $row ? ['id' => $row->uuid, 'name' => $row->name, 'won' => $stats[$bestId]['won'], 'leads' => $stats[$bestId]['leads']] : null;
        }

        return [
            'source' => $source ? ['platform' => $source->platform, 'leads' => (int) $source->leads, 'won' => (int) $source->won, 'share' => $total > 0 ? round((int) $source->leads / $total * 100, 1) : null] : null,
            'campaign' => $campaign,
            'partner' => $partner,
        ];
    }
}
