<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Leads\NextBestAction;
use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeadListResource;
use App\Models\Lead;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * The rep's whole screen, reduced to one question: who next?
 *
 * A list of forty leads asks a rep to judge which matters, forty times a day.
 * They mostly cannot, so they work top to bottom or work the ones they
 * remember, and the lead that needed a call an hour ago sits there. This
 * answers instead.
 */
class WorkQueueController extends Controller
{
    public function __construct(private NextBestAction $nextBest) {}

    /**
     * The single lead to work now.
     *
     * Candidates are narrowed in SQL and ranked in PHP, because urgency depends
     * on a target that varies per tenant and on comparisons between columns
     * that are awkward to express as one order-by. The SQL window is small and
     * bounded, so this stays one query regardless of how many leads exist.
     */
    public function next(Request $request)
    {
        $this->authorize('viewAny', Lead::class);

        $target = (int) ($request->user()->tenant?->settings?->first_contact_target_minutes ?? 30);

        $candidates = $this->openLeadsFor($request)
            ->orderByRaw('first_contact_at is not null')  // never-contacted first
            ->orderBy('next_follow_up_at')
            ->orderByDesc('score')
            ->limit(50)
            ->get();

        $ranked = $candidates
            ->map(fn (Lead $lead) => ['lead' => $lead, 'action' => $this->nextBest->for($lead, $target)])
            ->reject(fn (array $row) => $row['action']['action'] === 'wait'
                || $row['action']['action'] === 'none')
            ->sortBy(fn (array $row) => $this->nextBest->weight($row['action']))
            ->values();

        $top = $ranked->first();

        if (! $top) {
            // An empty queue is a result, not an error. A rep who has caught up
            // should be told so, not shown a spinner or a blank list.
            return ApiResponse::success([
                'lead' => null,
                'action' => null,
                'remaining' => 0,
            ]);
        }

        return ApiResponse::success([
            'lead' => new LeadListResource($top['lead']),
            'action' => $top['action'],
            'remaining' => $ranked->count(),
        ]);
    }

    /** Counts for the workspace's own summary, in one grouped pass. */
    public function summary(Request $request)
    {
        $this->authorize('viewAny', Lead::class);

        $target = (int) ($request->user()->tenant?->settings?->first_contact_target_minutes ?? 30);

        $row = $this->openLeadsFor($request)
            ->selectRaw(
                'count(*) as open,
                 count(*) filter (where first_contact_at is null) as uncontacted,
                 count(*) filter (where first_contact_at is null and created_at <= ?) as overdue_first_contact,
                 count(*) filter (where next_follow_up_at < now()) as overdue_follow_ups,
                 count(*) filter (where temperature = ?) as hot',
                [now()->subMinutes($target), 'hot'],
            )
            ->first();

        return ApiResponse::success([
            'open' => (int) $row->open,
            'uncontacted' => (int) $row->uncontacted,
            'overdue_first_contact' => (int) $row->overdue_first_contact,
            'overdue_follow_ups' => (int) $row->overdue_follow_ups,
            'hot' => (int) $row->hot,
        ]);
    }

    /** Open leads this user is allowed to see. */
    private function openLeadsFor(Request $request)
    {
        $closed = array_map(
            fn (LeadStatus $s) => $s->value,
            array_values(array_filter(LeadStatus::cases(), fn (LeadStatus $s) => $s->isClosed())),
        );

        return Lead::query()
            ->visibleTo($request->user())
            ->whereNotIn('status', $closed);
    }
}
