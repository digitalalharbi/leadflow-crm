<?php

namespace App\Domain\Insights;

use App\Domain\Campaigns\CampaignRevenue;
use App\Domain\Conversions\ConversionHealth;
use App\Domain\Leads\InterestSlaStats;
use App\Domain\Partners\PartnerStats;
use App\Domain\Sales\RepPerformance;
use App\Domain\Tracking\AttributionReport;
use App\Models\Lead;
use App\Models\Partner;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * From "something moved" to "do this": findings that compare things with each other, each with
 * what it means and what to do about it.
 *
 *   Campaign A has a lower cost per lead but weaker reservations than B.
 *   Partner B brings fewer leads but closes more of them.
 *   Rep X answers quickly but loses people after the visit.
 *   Campaign Y spends 28% of the budget and makes 9% of the reservations.
 *
 * Every item is {key, kind, severity, values, link} — the words are the interface's, in the
 * reader's language, from the catalogue `findings.<key>.{finding,impact,action}`; the server sends
 * the facts. Deterministic and silent without evidence: a comparison needs enough leads on both
 * sides, and money findings exist only when the reader may see money.
 */
class Recommendations
{
    /** Fewer leads than this and a rate is a coin toss. */
    private const MIN_LEADS = 10;

    /**
     * @param  array<string,mixed>|null  $revenue  CampaignRevenue::build() for the same window, when the caller has it
     * @return list<array{key:string,kind:string,severity:string,values:array<string,mixed>,link:?string}>
     */
    public function build(CarbonInterface $from, CarbonInterface $to, ?int $projectId = null, bool $money = true, string $currency = 'SAR', ?array $revenue = null, ?int $partnerId = null): array
    {
        $revenue ??= (new CampaignRevenue(null))->build($from, $to, $projectId);
        $campaigns = collect($revenue['campaigns']);

        $items = array_values(array_filter([
            ...($money ? [$this->cheapButWeak($campaigns, $currency), $this->budgetShareGap($campaigns), ...$this->spendWithoutQualified($campaigns, $currency)] : []),
            $partnerId === null ? $this->partnerClosesMore($from) : null,
            ...($partnerId === null ? $this->repLosesAfterVisit($from, $to, $projectId) : []),
            $this->funnelLeak($revenue['funnel']),
            $this->trackingStale(),
            $this->slaLate($from, $to, $projectId, $partnerId),
            $partnerId === null ? $this->noAttribution($from, $to, $projectId) : null,
        ]));

        $order = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($items, fn ($a, $b) => $order[$a['severity']] <=> $order[$b['severity']]);

        return array_slice($items, 0, 8);
    }

    /** @return array{key:string,kind:string,severity:string,values:array<string,mixed>,link:?string} */
    private function item(string $key, string $kind, string $severity, array $values, ?string $link = null): array
    {
        return compact('key', 'kind', 'severity', 'values', 'link');
    }

    /** A lower cost per lead that does not turn into reservations as well as a dearer one does. */
    private function cheapButWeak($campaigns, string $currency): ?array
    {
        $rows = $campaigns->filter(fn ($c) => $c['leads'] >= self::MIN_LEADS && ($c['cpl'] ?? null) !== null)->values();

        if ($rows->count() < 2) {
            return null;
        }

        $cheap = $rows->sortBy('cpl')->first();
        $rate = fn (array $c) => $c['leads'] > 0 ? $c['reservations'] / $c['leads'] * 100 : 0;
        $better = $rows->filter(fn ($c) => $c['id'] !== $cheap['id'] && $c['cpl'] > $cheap['cpl'] && $rate($c) >= max(3, $rate($cheap) * 1.5))->sortByDesc(fn ($c) => $rate($c))->first();

        return $better === null ? null : $this->item('cheap_but_weak', 'recommendation', 'warning', [
            'a' => $cheap['name'], 'b' => $better['name'], 'cpl_a' => round($cheap['cpl'], 2), 'cpl_b' => round($better['cpl'], 2),
            'rate_a' => round($rate($cheap), 1), 'rate_b' => round($rate($better), 1), 'currency' => $currency,
        ], '/campaigns/'.$better['id']);
    }

    /** A campaign that takes a big share of the spend and a small one of the reservations. */
    private function budgetShareGap($campaigns): ?array
    {
        $spend = $campaigns->sum(fn ($c) => (float) ($c['spend'] ?? 0));
        $reservations = $campaigns->sum('reservations');

        if ($spend <= 0 || $reservations < 3) {
            return null;
        }

        $worst = $campaigns->map(fn ($c) => $c + [
            'spend_share' => ((float) ($c['spend'] ?? 0)) / $spend * 100,
            'res_share' => $c['reservations'] / $reservations * 100,
        ])->filter(fn ($c) => $c['spend_share'] >= 20 && $c['res_share'] <= $c['spend_share'] * 0.5)->sortByDesc(fn ($c) => $c['spend_share'] - $c['res_share'])->first();

        return $worst === null ? null : $this->item('budget_share_gap', 'recommendation', 'warning', [
            'name' => $worst['name'], 'spend_share' => (int) round($worst['spend_share']), 'res_share' => (int) round($worst['res_share']),
        ], '/campaigns/'.$worst['id']);
    }

    /** @return list<array<string,mixed>> */
    private function spendWithoutQualified($campaigns, string $currency): array
    {
        return $campaigns->filter(fn ($c) => ($c['spend'] ?? 0) > 0 && $c['leads'] >= 5 && $c['qualified'] === 0)->sortByDesc('spend')->take(1)
            ->map(fn ($c) => $this->item('spend_without_qualified', 'recommendation', 'critical', ['name' => $c['name'], 'spend' => round($c['spend']), 'currency' => $currency], '/campaigns/'.$c['id']))->values()->all();
    }

    /** A partner with fewer leads than the others and a clearly better close rate. */
    private function partnerClosesMore(CarbonInterface $from): ?array
    {
        $partners = Partner::query()->where('is_active', true)->get(['id', 'name']);

        if ($partners->count() < 2) {
            return null;
        }

        $stats = (new PartnerStats)->for($partners->pluck('id')->all(), $from);
        $active = $partners->filter(fn ($p) => $stats[$p->id]['leads'] > 0);
        $leads = $active->sum(fn ($p) => $stats[$p->id]['leads']);
        $won = $active->sum(fn ($p) => $stats[$p->id]['won']);

        if ($active->count() < 2 || $leads < self::MIN_LEADS || $won === 0) {
            return null;
        }

        $avg = $won / $leads * 100;
        $meanLeads = $leads / $active->count();

        $best = $active->filter(fn ($p) => $stats[$p->id]['leads'] >= 3 && $stats[$p->id]['leads'] < $meanLeads && $stats[$p->id]['won'] / $stats[$p->id]['leads'] * 100 >= $avg * 1.3)
            ->sortByDesc(fn ($p) => $stats[$p->id]['won'] / $stats[$p->id]['leads'])->first();

        return $best === null ? null : $this->item('partner_closes_more', 'recommendation', 'info', [
            'name' => $best->name, 'rate' => round($stats[$best->id]['won'] / $stats[$best->id]['leads'] * 100, 1), 'avg' => round($avg, 1),
        ], '/partners');
    }

    /** @return list<array<string,mixed>> */
    private function repLosesAfterVisit(CarbonInterface $from, CarbonInterface $to, ?int $projectId): array
    {
        $data = (new RepPerformance)->build($from, $to, $projectId, false);
        $team = $data['team'];

        if ($team['reservation_rate'] === null) {
            return [];
        }

        $responses = array_filter(array_column($data['reps'], 'response_minutes'), fn ($m) => $m !== null);
        $median = $responses === [] ? null : collect($responses)->sort()->values()->get((int) floor((count($responses) - 1) / 2));

        return collect($data['reps'])->filter(fn ($r) => ! $r['small_sample'] && $r['visits'] >= 3 && $r['response_minutes'] !== null && $median !== null
            && $r['response_minutes'] <= $median && $r['reservation_rate'] !== null && $r['reservation_rate'] <= $team['reservation_rate'] * 0.6)
            ->sortBy('reservation_rate')->take(1)
            ->map(fn ($r) => $this->item('rep_loses_after_visit', 'recommendation', 'warning', [
                'name' => $r['name'], 'response' => $r['response_minutes'], 'rate' => $r['reservation_rate'], 'avg' => $team['reservation_rate'],
            ], '/reports'))->values()->all();
    }

    /** The step where the most people are lost. @param  list<array<string,mixed>>  $funnel */
    private function funnelLeak(array $funnel): ?array
    {
        $worst = collect($funnel)->filter(fn ($s) => ($s['lost'] ?? null) !== null)->sortByDesc('lost')->first();
        $index = $worst ? array_search($worst['key'], array_column($funnel, 'key'), true) : false;

        if ($worst === null || $index === false || $index < 1 || ($funnel[$index - 1]['count'] ?? 0) < self::MIN_LEADS || $worst['lost'] < 20) {
            return null;
        }

        return $this->item('funnel_leak', 'insight', 'info', ['from' => $funnel[$index - 1]['key'], 'to' => $worst['key'], 'lost' => $worst['lost']], '/campaigns');
    }

    /** A platform that was sending conversions and has gone quiet. */
    private function trackingStale(): ?array
    {
        $stale = collect(app(ConversionHealth::class)->summary())
            ->filter(fn ($p) => $p['configured'] && $p['last_sent_at'] !== null && strtotime($p['last_sent_at']) < now()->subDays(5)->timestamp)
            ->sortBy('last_sent_at')->first();

        return $stale === null ? null : $this->item('tracking_stale', 'insight', 'warning', [
            'platform' => $stale['platform'], 'days' => (int) floor((now()->timestamp - strtotime($stale['last_sent_at'])) / 86400),
        ], '/tracking');
    }

    /** Interested people past their reply window. */
    private function slaLate(CarbonInterface $from, CarbonInterface $to, ?int $projectId, ?int $partnerId): ?array
    {
        $leads = Lead::query()->whereBetween('leads.created_at', [$from, $to])
            ->when($projectId, fn ($q) => $q->where('leads.project_id', $projectId))->when($partnerId, fn ($q) => $q->where('leads.partner_id', $partnerId));
        $late = InterestSlaStats::for($leads)['late'];

        return $late > 0 ? $this->item('sla_late', 'insight', $late >= 5 ? 'critical' : 'warning', ['count' => $late], '/work') : null;
    }

    /**
     * Leads nobody can trace to a source — measured the way the sources chart reads them (the lead's own platform or
     * source), so the two never contradict each other. A lead whose platform is known but which arrived without a
     * tracking link is a different, smaller problem, and is said as that.
     */
    private function noAttribution(CarbonInterface $from, CarbonInterface $to, ?int $projectId): ?array
    {
        $leads = Lead::query()->whereBetween('leads.created_at', [$from, $to])->when($projectId, fn ($q) => $q->where('leads.project_id', $projectId));
        $total = (clone $leads)->count();

        if ($total < self::MIN_LEADS) {
            return null;
        }

        $unknown = (clone $leads)->where(fn ($q) => $q->where(fn ($p) => $p->whereNull('leads.platform')->orWhere('leads.platform', ''))
            ->where(fn ($p) => $p->whereNull('leads.source')->orWhere('leads.source', '')))->count();
        $unknownPercent = $unknown / $total * 100;

        if ($unknownPercent >= 30) {
            return $this->item('no_attribution', 'insight', 'info', ['percent' => (int) round($unknownPercent)], '/data-quality');
        }

        $untracked = app(AttributionReport::class)->build('last', 'channel', Carbon::instance($from), Carbon::instance($to), $projectId)['totals']['unattributed_percent'] ?? 0;

        return $untracked >= 30
            ? $this->item('no_tracking_links', 'insight', 'info', ['percent' => (int) round($untracked)], '/tracking')
            : null;
    }
}
