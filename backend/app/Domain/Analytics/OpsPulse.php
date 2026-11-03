<?php

namespace App\Domain\Analytics;

use App\Domain\Leads\InterestEngine;
use App\Domain\Leads\InterestSla;
use App\Domain\Leads\LeadViews;
use App\Domain\Tracking\TrackingHealth;
use App\Models\Anomaly;
use App\Models\Appointment;
use App\Models\AutomationActionLog;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Reservation;
use App\Models\TenantSetting;
use App\Models\User;

/**
 * The operations home in one pass: how today is going, and whether the machinery is healthy.
 *
 * Nine answers a person opens the product to get — leads today, interested, hot, needing a
 * follow-up, late on their reply window, visits today, reservations waiting, won today, revenue
 * today — and four health lights (automation, tracking, campaigns, the sales backlog). Each
 * number is one the screen it links to will confirm.
 */
class OpsPulse
{
    /** @return array<string,mixed> */
    public function for(User $user): array
    {
        $leads = fn () => Lead::query()->visibleTo($user)->reorder();
        $open = ['won', 'lost', 'invalid', 'no_response'];
        $today = now()->startOfDay();
        $target = (int) (TenantSetting::query()->value('first_contact_target_minutes') ?? 30);

        $interested = $leads()->whereNotIn('leads.status', $open)->whereIn('leads.interest_level', InterestEngine::INTERESTED)
            ->get(['leads.id', 'leads.tenant_id', 'leads.project_id', 'leads.status', 'leads.interest_level', 'leads.interest_level_changed_at', 'leads.interest_at', 'leads.last_customer_message_at', 'leads.last_contact_at', 'leads.created_at']);

        $late = $interested->filter(fn (Lead $l) => (InterestSla::for($l)['state'] ?? null) === 'breached')->count();

        $followUps = FollowUp::query()->pending()->where('due_at', '<', now())->when(! $user->seesAllLeads(), fn ($q) => $q->where('assigned_to', $user->id))->count();
        $uncontacted = $leads()->whereNull('leads.first_contact_at')->where('leads.status', 'new')->where('leads.created_at', '<=', now()->subMinutes($target))->count();

        $won = $leads()->where('leads.won_at', '>=', $today);

        $answers = [
            'leads_today' => $leads()->where('leads.created_at', '>=', $today)->count(),
            'interested' => $interested->count(),
            'hot' => $leads()->whereNotIn('leads.status', $open)->where(fn ($q) => $q->where('leads.temperature', 'hot')->orWhereIn('leads.interest_level', ['hot', 'visit_ready', 'reservation_ready']))->count(),
            'needs_follow_up' => $followUps + $uncontacted,
            'sla_late' => $late,
            // Automation handed it over and nobody has picked it up.
            'needs_human' => $leads()->whereRaw(LeadViews::sql(TenantSetting::query()->value('timezone') ?? 'UTC')['needs_human'])->count(),
            'visits_today' => Appointment::query()->whereIn('status', Appointment::BOOKED)->whereBetween('scheduled_at', [$today, now()->endOfDay()])->when(! $user->seesAllLeads(), fn ($q) => $q->where('assigned_to', $user->id))->count(),
            'reservations_pending' => Reservation::query()->where('status', 'pending')->when(! $user->seesAllLeads(), fn ($q) => $q->whereHas('lead', fn ($l) => $l->where('assigned_to', $user->id)))->count(),
            'won_today' => (clone $won)->count(),
            'revenue_today' => round(((int) (clone $won)->sum('deal_value_minor')) / 100, 2),
        ];

        return ['answers' => $answers, 'health' => $user->seesAllLeads() ? $this->health($uncontacted + $followUps, $late) : null];
    }

    /**
     * Four lights, each ok / watch / bad with the number that decided it.
     *
     * @return array<string,array{state:string,count:int}>
     */
    private function health(int $backlog, int $late): array
    {
        // Limited to some projects: the failures of those, not a count of what happens elsewhere.
        $user = request()->user();
        $mine = fn ($query) => $user?->isProjectScoped() ? $query->whereIn('lead_id', Lead::query()->visibleTo($user)->select('leads.id')) : $query;

        $failed = $mine(AutomationActionLog::query())->where('result', AutomationActionLog::FAILED)->where('happened_at', '>=', now()->subDay())->count();
        $stuck = $mine(AutomationActionLog::query())->where('result', AutomationActionLog::NEEDS_HUMAN)->where('happened_at', '>=', now()->subDay())->count();

        $tracking = collect(app(TrackingHealth::class)->build(7)['platforms'])->filter(fn ($p) => $p['connected'] && $p['status'] === 'risk')->count();

        $anomalies = Anomaly::query()->whereNull('dismissed_at')->where('detected_on', '>=', now()->subDays(3)->toDateString())->get(['severity', 'kind']);
        $campaigns = $anomalies->whereIn('kind', ['spend_no_qualified', 'cpl_spike', 'manual_spend_stale'])->count();

        $light = fn (int $n, int $bad) => $n === 0 ? 'ok' : ($n >= $bad ? 'bad' : 'watch');

        return [
            'automation' => ['state' => $light($failed + $stuck, 10), 'count' => $failed + $stuck],
            'tracking' => ['state' => $light($tracking, 2), 'count' => $tracking],
            'campaigns' => ['state' => $anomalies->where('severity', 'critical')->whereIn('kind', ['spend_no_qualified', 'cpl_spike', 'manual_spend_stale'])->isNotEmpty() ? 'bad' : $light($campaigns, 99), 'count' => $campaigns],
            'backlog' => ['state' => $light($backlog + $late, 25), 'count' => $backlog + $late],
        ];
    }
}
