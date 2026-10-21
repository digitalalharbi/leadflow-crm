<?php

namespace App\Domain\Insights;

use App\Domain\Access\Permissions;
use App\Domain\Analytics\LeadFunnel;
use App\Domain\Campaigns\CampaignRevenue;
use App\Domain\Leads\InterestSla;
use App\Models\Anomaly;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Partner;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * What to look at first, in a few words each.
 *
 * Every card is a fact with its numbers and a place to go — deterministic, so a manager can check
 * it against the screen it points at, and silent when there is nothing to say: no card is better
 * than a confident one built on five leads. Money cards are only made for people who may see money.
 */
class InsightEngine
{
    private const MIN_SAMPLE = 8;

    /**
     * @return array{cards:list<array{key:string,tone:string,values:array<string,mixed>,href:?string}>, anomalies:list<array<string,mixed>>}
     */
    public function for(User $user): array
    {
        $cards = array_values(array_filter([
            $this->holdsExpiring($user),
            $this->interestedWaiting($user),
            $this->visitGap($user),
            $this->noShows($user),
            $this->funnelLeak($user),
            ...$this->money($user),
            $this->topPartner($user),
        ]));

        $order = ['bad' => 0, 'warn' => 1, 'good' => 2, 'neutral' => 3];
        usort($cards, fn ($a, $b) => $order[$a['tone']] <=> $order[$b['tone']]);

        return ['cards' => array_slice($cards, 0, 6), // What moved outside its normal range is the team's business, not one rep's.
            'anomalies' => $user->seesAllLeads() ? $this->anomalies() : []];
    }

    /** @return list<array<string,mixed>> */
    public function anomalies(): array
    {
        return Anomaly::query()->whereNull('dismissed_at')->where('detected_on', '>=', now()->subDays(3)->toDateString())
            ->orderByRaw("case severity when 'critical' then 0 else 1 end")->latest('detected_on')->limit(6)->get()
            ->map(fn (Anomaly $a) => [
                'id' => $a->uuid, 'kind' => $a->kind, 'subject' => $a->subject ?: null, 'severity' => $a->severity,
                'observed' => $a->observed, 'expected' => $a->expected, 'detected_on' => $a->detected_on?->toDateString(),
            ])->all();
    }

    private function holdsExpiring(User $user): ?array
    {
        $n = Reservation::query()->where('status', 'pending')->whereNotNull('expires_at')->whereBetween('expires_at', [now(), now()->addDay()])
            ->when(! $user->seesAllLeads(), fn ($q) => $q->whereHas('lead', fn ($l) => $l->where('assigned_to', $user->id)))->count();

        return $n > 0 ? ['key' => 'holds_expiring', 'tone' => 'warn', 'values' => ['count' => $n], 'href' => '/visits'] : null;
    }

    private function interestedWaiting(User $user): ?array
    {
        $late = Lead::query()->visibleTo($user)->reorder()->whereIn('leads.interest_level', array_keys(InterestSla::KEYS))->whereNotIn('leads.status', ['won', 'lost', 'invalid', 'no_response'])
            ->limit(500)->get(['leads.id', 'leads.tenant_id', 'leads.project_id', 'leads.status', 'leads.interest_level', 'leads.interest_level_changed_at', 'leads.interest_at', 'leads.last_customer_message_at', 'leads.last_contact_at', 'leads.created_at'])
            ->filter(fn (Lead $l) => (InterestSla::for($l)['state'] ?? null) === 'breached')->count();

        return $late > 0 ? ['key' => 'interested_waiting', 'tone' => 'bad', 'values' => ['count' => $late], 'href' => '/work'] : null;
    }

    /** Interested for a week and nobody has booked a visit. */
    private function visitGap(User $user): ?array
    {
        $n = Lead::query()->visibleTo($user)->reorder()->whereIn('leads.interest_level', ['interested', 'hot'])->whereNotIn('leads.status', ['won', 'lost', 'invalid', 'no_response'])
            ->where('leads.interest_level_changed_at', '<=', now()->subDays(7))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('appointments as a')->whereColumn('a.lead_id', 'leads.id')->whereNull('a.deleted_at')->whereNotIn('a.status', ['cancelled']))
            ->count();

        return $n >= 3 ? ['key' => 'visit_gap', 'tone' => 'warn', 'values' => ['count' => $n], 'href' => '/leads?view=interested'] : null;
    }

    private function noShows(User $user): ?array
    {
        $n = Appointment::query()->where('status', Appointment::NO_SHOW)->where('no_show_at', '>=', now()->subDays(7))
            ->when(! $user->seesAllLeads(), fn ($q) => $q->where('assigned_to', $user->id))->count();

        return $n >= 2 ? ['key' => 'no_shows', 'tone' => 'warn', 'values' => ['count' => $n], 'href' => '/visits'] : null;
    }

    /** Where the funnel loses the most between the steps a salesperson influences. */
    private function funnelLeak(User $user): ?array
    {
        $steps = LeadFunnel::counts(Lead::query()->visibleTo($user)->where('leads.created_at', '>=', now()->subDays(30)))['steps'];
        $pairs = [['interested', 'visit'], ['visit', 'reservation'], ['reservation', 'won']];
        $worst = null;

        foreach ($pairs as [$from, $to]) {
            if ($steps[$from] < self::MIN_SAMPLE) {
                continue;
            }

            $lost = round(($steps[$from] - $steps[$to]) / $steps[$from] * 100);

            if ($worst === null || $lost > $worst['lost']) {
                $worst = ['from' => $from, 'to' => $to, 'lost' => $lost, 'count' => $steps[$from] - $steps[$to]];
            }
        }

        return $worst !== null && $worst['lost'] >= 50 ? ['key' => 'funnel_leak', 'tone' => 'warn', 'values' => $worst, 'href' => '/'] : null;
    }

    /** @return list<array<string,mixed>> */
    private function money(User $user): array
    {
        if (! Permissions::has($user, 'spend.view')) {
            return [];
        }

        $to = CarbonImmutable::now()->endOfDay();
        $report = (new CampaignRevenue($user))->build($to->subDays(29)->startOfDay(), $to);
        $out = [];

        $best = $report['rankings']['roas'][0] ?? null;
        if ($best && $best['roas'] >= 1 && Permissions::has($user, 'revenue.view')) {
            $out[] = ['key' => 'best_campaign', 'tone' => 'good', 'values' => ['name' => $best['name'], 'roas' => $best['roas']], 'href' => '/campaigns'];
        }

        $waste = $report['rankings']['wasted'][0] ?? null;
        if ($waste) {
            $out[] = ['key' => 'wasted_spend', 'tone' => 'bad', 'values' => ['name' => $waste['name'], 'spend' => $waste['spend']], 'href' => '/campaigns'];
        }

        return $out;
    }

    private function topPartner(User $user): ?array
    {
        $row = Lead::query()->visibleTo($user)->reorder()->whereNotNull('leads.partner_id')->where('leads.status', 'won')->where('leads.won_at', '>=', now()->subDays(30))
            ->selectRaw('leads.partner_id, count(*) as won')->groupBy('leads.partner_id')->orderByDesc('won')->first();

        $partner = $row ? Partner::find($row->partner_id) : null;

        return $partner && (int) $row->won >= 1 ? ['key' => 'top_partner', 'tone' => 'good', 'values' => ['name' => $partner->name, 'won' => (int) $row->won], 'href' => '/partners'] : null;
    }
}
