<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Leads\NextBestAction;
use App\Domain\Leads\OutcomeRecorder;
use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeadListResource;
use App\Models\Lead;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

        /*
         * Leads the rep passed over in this sitting. Held by the client rather
         * than the server on purpose: skipping is "not me, not now", not a
         * decision about the lead, so it must not outlive the sitting or affect
         * anybody else's queue. The window below is widened by however many
         * were skipped, so a rep who skips several still reaches new ones.
         */
        $skipped = collect($request->input('skip', []))
            ->filter(fn ($id) => is_string($id) && preg_match('/^[0-9a-f-]{36}$/', $id) === 1)
            ->take(50)
            ->values()
            ->all();

        $candidates = $this->openLeadsFor($request)
            ->when($skipped !== [], fn ($q) => $q->whereNotIn('uuid', $skipped))
            ->orderByRaw('first_contact_at is not null')  // never-contacted first
            ->orderBy('next_follow_up_at')
            ->orderByDesc('score')
            ->limit(50 + count($skipped))
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

    /**
     * Record what happened, and hand over the next lead in the same response.
     *
     * One round trip on purpose. The loop this screen exists for is "act,
     * record, next" — splitting it into a POST and then a GET puts a spinner in
     * the middle of a rep's rhythm, and on a phone in a car park it puts a
     * failure there instead: the outcome saved, the next lead never arrived,
     * and the rep cannot tell which half went through.
     */
    public function outcome(Request $request, OutcomeRecorder $recorder)
    {
        $data = $request->validate([
            'lead_id' => ['required', 'string'],
            'outcome' => ['required', 'string', Rule::in(OutcomeRecorder::available())],
            'note' => ['nullable', 'string', 'max:2000'],
            'skip' => ['sometimes', 'array'],
        ]);

        $lead = Lead::where('uuid', $data['lead_id'])->firstOrFail();
        $this->authorize('update', $lead);

        $result = $recorder->record($lead, $data['outcome'], $request->user(), $data['note'] ?? null);

        // The lead just worked is never the next one offered, even when the
        // outcome left it open — a rep who records "no answer" and is handed
        // the same name back reasonably concludes the button did nothing.
        $request->merge(['skip' => array_merge($data['skip'] ?? [], [$lead->uuid])]);

        return ApiResponse::success([
            'recorded' => $result['summary'],
            'stage_changed' => $result['stage_changed'],
            'next' => $this->next($request)->getData(true)['data'] ?? null,
        ]);
    }
}
