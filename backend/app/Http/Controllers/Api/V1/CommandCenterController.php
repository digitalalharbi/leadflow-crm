<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Leads\LeadIntelligence;
use App\Domain\Leads\NextBestAction;
use App\Domain\Leads\PipelineOverview;
use App\Domain\Sla\SlaMonitor;
use App\Enums\LeadStatus;
use App\Enums\MessageStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AutomationRun;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What needs a person, right now, in one request.
 *
 * The dashboard's figures describe a period; this describes the moment. It is
 * the difference between "39 leads this month" and "three of them are waiting
 * on a first reply and one of those is hot" — the second is what somebody
 * opens the product to find out, and it should not take scrolling to reach.
 *
 * Every row is a count of something a person can go and fix, with the place
 * to fix it. A zero is not shown: a screen that lists eight things that are
 * fine teaches people not to read it.
 */
class CommandCenterController extends Controller
{
    public function __construct(private NextBestAction $nextBest) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Lead::class);

        $user = $request->user();
        $target = (int) ($user->tenant?->settings?->first_contact_target_minutes ?? 30);
        $closed = [LeadStatus::Won->value, LeadStatus::Lost->value];

        $open = fn () => Lead::query()->visibleTo($user)->whereNotIn('status', $closed);

        $tally = $open()
            ->selectRaw(
                'count(*) as open,
                 count(*) filter (where first_contact_at is null and status = ? and created_at <= ?) as sla_breached,
                 count(*) filter (where next_follow_up_at < now()) as overdue_follow_ups,
                 count(*) filter (where temperature = ?) as hot,
                 count(*) filter (where is_priority) as priority,
                 count(*) filter (where wrong_fit) as wrong_fit,
                 count(*) filter (where assigned_to is null) as unassigned',
                [LeadStatus::New->value, now()->subMinutes($target), 'hot'],
            )
            ->first();

        $awaitingOutcome = Appointment::query()
            ->when(! $user->seesAllLeads(), fn ($q) => $q->where('assigned_to', $user->id))
            ->awaitingOutcome()
            ->count();

        // Both are the last week: a failure from March is history, not a task.
        $since = now()->subDays(7);

        $automationFailures = AutomationRun::query()
            ->where('outcome', 'failed')->where('created_at', '>=', $since)->count();

        $messageFailures = LeadMessage::query()
            ->where('status', MessageStatus::Failed->value)->where('created_at', '>=', $since)->count();

        $stalled = Lead::query()->visibleTo($user)->whereRaw(PipelineOverview::stalledSql())->count();

        $attention = collect([
            ['key' => 'priority', 'severity' => 'critical', 'count' => (int) $tally->priority, 'href' => '/leads?priority=1'],
            ['key' => 'sla_breached', 'severity' => 'critical', 'count' => (int) $tally->sla_breached, 'href' => '/work', 'minutes' => $target],
            ['key' => 'overdue_follow_ups', 'severity' => 'critical', 'count' => (int) $tally->overdue_follow_ups, 'href' => '/follow-ups'],
            ['key' => 'stalled', 'severity' => 'warning', 'count' => $stalled, 'href' => '/leads?view=stalled'],
            ['key' => 'wrong_fit', 'severity' => 'warning', 'count' => (int) $tally->wrong_fit, 'href' => '/leads?wrong_fit=1'],
            ['key' => 'hot', 'severity' => 'warning', 'count' => (int) $tally->hot, 'href' => '/leads?temperature=hot'],
            ['key' => 'appointments_awaiting_outcome', 'severity' => 'warning', 'count' => $awaitingOutcome, 'href' => '/follow-ups'],
            ['key' => 'unassigned', 'severity' => 'warning', 'count' => $user->seesAllLeads() ? (int) $tally->unassigned : 0, 'href' => '/leads'],
            ['key' => 'automation_failures', 'severity' => 'warning', 'count' => $user->seesAllLeads() ? $automationFailures : 0, 'href' => '/automation'],
            ['key' => 'message_failures', 'severity' => 'warning', 'count' => $user->seesAllLeads() ? $messageFailures : 0, 'href' => '/leads'],
        ])->filter(fn (array $row) => $row['count'] > 0)->values();

        return ApiResponse::success([
            'open_leads' => (int) $tally->open,
            'attention' => $attention,
            'recommended' => $this->recommended($open(), $target),
            'appointments_today' => $this->appointmentsToday($user),
            'workload' => $user->seesAllLeads() ? $this->workload($user, $closed) : [],
            'target_minutes' => $target,
            // The response promise, as numbers a manager can watch.
            'sla' => app(SlaMonitor::class)->summary($user, $target),
        ]);
    }

    /**
     * The five leads to work first, each with the reason in words.
     *
     * Ranked exactly as the work queue ranks them — the same candidates, the
     * same rules — because a dashboard that recommends one lead while "اعمل
     * الآن" opens another is two products disagreeing about the same customer.
     */
    private function recommended($query, int $target): array
    {
        return $query
            ->with(['project:id,uuid,name', 'assignee:id,uuid,name'])
            // A priority lead is always inside the window the ranking sees.
            ->orderByDesc('is_priority')
            ->orderByRaw('first_contact_at is not null')
            ->orderBy('next_follow_up_at')
            ->orderByDesc('score')
            ->limit(40)
            ->get()
            // The same facts the work queue ranks on, so both screens agree.
            ->pipe(fn ($leads) => app(LeadIntelligence::class)->attach($leads))
            ->map(fn (Lead $lead) => ['lead' => $lead, 'action' => $this->nextBest->for($lead, $target)])
            ->reject(fn (array $row) => in_array($row['action']['action'], ['wait', 'none'], true))
            ->sortBy(fn (array $row) => $this->nextBest->weight($row['action']))
            ->take(5)
            ->map(fn (array $row) => [
                'id' => $row['lead']->uuid,
                'name' => $row['lead']->full_name,
                'project' => $row['lead']->project?->name,
                'temperature' => $row['lead']->temperature?->value,
                'score' => $row['lead']->score,
                'action' => $row['action']['action'],
                'urgency' => $row['action']['urgency'],
                'reason' => $row['action']['reason'],
            ])
            ->values()
            ->all();
    }

    private function appointmentsToday(User $user): array
    {
        return Appointment::query()
            ->with('lead:id,uuid,full_name')
            ->whereBetween('scheduled_at', [now()->startOfDay(), now()->endOfDay()])
            ->where('status', 'scheduled')
            ->when(! $user->seesAllLeads(), fn ($q) => $q->where('assigned_to', $user->id))
            ->orderBy('scheduled_at')
            ->limit(6)
            ->get()
            ->map(fn (Appointment $a) => [
                'id' => $a->uuid,
                'lead_id' => $a->lead?->uuid,
                'lead' => $a->lead?->full_name,
                'at' => $a->scheduled_at->toIso8601String(),
                'location' => $a->location,
            ])
            ->all();
    }

    /**
     * Who is carrying what. One grouped pass, then joined to names.
     *
     * Shown to people who can see every lead only: a rep looking at a league
     * table of colleagues' backlogs is a different product from one looking at
     * their own queue.
     *
     * @param  array<int,string>  $closed
     */
    private function workload(User $user, array $closed): array
    {
        $rows = Lead::query()
            ->whereNotIn('status', $closed)
            ->whereNotNull('assigned_to')
            ->select('assigned_to', DB::raw('count(*) as open'),
                DB::raw('count(*) filter (where next_follow_up_at < now()) as overdue'),
                DB::raw('count(*) filter (where temperature = \'hot\') as hot'))
            ->groupBy('assigned_to')
            ->get()
            ->keyBy('assigned_to');

        return User::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('is_active', true)
            ->whereIn('id', $rows->keys())
            ->orderBy('name')
            ->get(['id', 'uuid', 'name'])
            ->map(fn (User $u) => [
                'id' => $u->uuid,
                'name' => $u->name,
                'open' => (int) $rows[$u->id]->open,
                'overdue' => (int) $rows[$u->id]->overdue,
                'hot' => (int) $rows[$u->id]->hot,
            ])
            ->sortByDesc('open')
            ->values()
            ->all();
    }
}
