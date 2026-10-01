<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeadListResource;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Uuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The first screen. Answers, in one request: how many new leads, who needs
 * following up today, who is late, who is hot, and how the funnel is converting.
 */
class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Lead::class);

        $user = $request->user();

        // All cards share one filtered base query so the numbers always agree.
        $base = fn (): Builder => $this->baseQuery($request);

        $statusCounts = (clone $base())
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $total = (int) $statusCounts->sum();
        $won = (int) ($statusCounts[LeadStatus::Won->value] ?? 0);
        $qualified = (int) ($statusCounts[LeadStatus::Qualified->value] ?? 0);
        $appointments = (int) ($statusCounts[LeadStatus::Appointment->value] ?? 0);

        /*
         * One pass for the remaining lead figures. These were four separate
         * COUNT queries over the same filtered set; aggregate FILTER gets them
         * in a single scan, which matters because this is the most-hit endpoint
         * in the product and the set grows with the tenant.
         */
        $tallies = (clone $base())
            ->selectRaw(
                'count(*) filter (where temperature = ? and status not in (?, ?)) as hot,
                 count(*) filter (where assigned_to is null) as unassigned,
                 count(*) filter (where created_at >= ?) as new_today',
                [
                    LeadTemperature::Hot->value,
                    LeadStatus::Won->value,
                    LeadStatus::Lost->value,
                    today(),
                ],
            )
            ->first();

        // Likewise for the follow-up buckets: one grouped query, not three counts.
        $followUpCounts = FollowUp::query()
            ->visibleTo($user)
            ->pending()
            ->selectRaw(
                'count(*) filter (where due_at < ?) as overdue,
                 count(*) filter (where due_at >= ? and due_at <= ?) as due_today',
                [now(), now(), now()->endOfDay()],
            )
            ->first();

        return ApiResponse::success([
            'summary' => [
                'total_leads' => $total,
                'new' => (int) ($statusCounts[LeadStatus::New->value] ?? 0),
                'contacted' => (int) ($statusCounts[LeadStatus::Contacted->value] ?? 0),
                'qualified' => $qualified,
                'appointments' => $appointments,
                'won' => $won,
                'lost' => (int) ($statusCounts[LeadStatus::Lost->value] ?? 0),
                // Conversion = won / everything that entered the funnel.
                'conversion_rate' => $total > 0 ? round($won / $total * 100, 1) : 0.0,
                'hot_leads' => (int) ($tallies->hot ?? 0),
                'unassigned' => (int) ($tallies->unassigned ?? 0),
            ],
            'today' => [
                'new_leads' => (int) ($tallies->new_today ?? 0),
                'follow_ups_today' => (int) ($followUpCounts->due_today ?? 0),
                'follow_ups_overdue' => (int) ($followUpCounts->overdue ?? 0),
                'appointments' => $appointments,
            ],
            'charts' => [
                'by_platform' => $this->groupCount($base(), 'platform'),
                'by_status' => LeadStatus::values() === [] ? [] : collect(LeadStatus::cases())
                    ->map(fn ($s) => [
                        'key' => $s->value,
                        'count' => (int) ($statusCounts[$s->value] ?? 0),
                    ])->values(),
                'by_campaign' => $this->groupCount($base(), 'campaign_name', 8),
                'trend' => $this->dailyTrend($base()),
            ],
            'hot_leads' => LeadListResource::collection(
                (clone $base())
                    ->where('temperature', LeadTemperature::Hot->value)
                    ->whereNotIn('status', [LeadStatus::Won->value, LeadStatus::Lost->value])
                    ->with(['assignee:id,uuid,name', 'project:id,uuid,name'])
                    ->orderByDesc('score')->limit(5)->get()
            ),
            'overdue_leads' => LeadListResource::collection(
                (clone $base())->overdueFollowUp()
                    ->with(['assignee:id,uuid,name', 'project:id,uuid,name'])
                    ->orderBy('next_follow_up_at')->limit(5)->get()
            ),
            'filters' => [
                'projects' => Project::orderBy('name')->get(['uuid', 'name'])
                    ->map(fn ($p) => ['id' => $p->uuid, 'name' => $p->name])->values(),
                'users' => $user->seesAllLeads()
                    ? User::where('tenant_id', $user->tenant_id)->where('is_active', true)
                        ->orderBy('name')->get(['uuid', 'name'])
                        ->map(fn ($u) => ['id' => $u->uuid, 'name' => $u->name])->values()
                    : [],
            ],
        ]);
    }

    private function baseQuery(Request $request): Builder
    {
        $query = Lead::query()->visibleTo($request->user());

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        if ($request->filled('project_id')) {
            $query->whereHas('project', fn ($p) => $p->whereIn('uuid', Uuid::onlyValid($request->input('project_id'))));
        }

        if ($request->filled('assigned_to') && $request->user()->seesAllLeads()) {
            $query->whereHas('assignee', fn ($u) => $u->whereIn('uuid', Uuid::onlyValid($request->input('assigned_to'))));
        }

        return $query;
    }

    private function groupCount(Builder $query, string $column, int $limit = 10): array
    {
        return $query->select($column, DB::raw('count(*) as aggregate'))
            ->whereNotNull($column)
            ->groupBy($column)
            ->orderByDesc('aggregate')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => ['key' => $row->{$column}, 'count' => (int) $row->aggregate])
            ->all();
    }

    /** Last 14 days of inbound volume, zero-filled so the chart has no gaps. */
    private function dailyTrend(Builder $query): array
    {
        $from = today()->subDays(13);

        $rows = $query->where('created_at', '>=', $from)
            ->select(DB::raw('date(created_at) as day'), DB::raw('count(*) as aggregate'))
            ->groupBy('day')
            ->pluck('aggregate', 'day');

        $series = [];

        for ($i = 0; $i < 14; $i++) {
            $day = $from->copy()->addDays($i)->toDateString();
            $series[] = ['key' => $day, 'count' => (int) ($rows[$day] ?? 0)];
        }

        return $series;
    }
}
