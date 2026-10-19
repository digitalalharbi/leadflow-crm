<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Leads\NextBestAction;
use App\Domain\Leads\OutcomeRecorder;
use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeadListResource;
use App\Models\Lead;
use App\Models\LeadEngagement;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
            // A priority lead is always inside the window the ranking sees.
            ->orderByDesc('is_priority')
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
            /*
             * The few behind this one, and the last few things that happened to
             * this one. Both are on the screen beside the card, so both come
             * back with it: a rep who has to click to find out why a lead is
             * urgent has already lost the time the queue was saving them.
             */
            'queue' => $this->queue($ranked),
            'timeline' => $this->timeline($top['lead']),
            'signals' => $this->signals($top['lead']),
        ]);
    }

    /**
     * What is behind the current card, in the order it will be worked.
     *
     * Six, not forty. The point of the queue is that nobody is choosing from a
     * list; this is here so a rep can see that the order is sane and that the
     * one they are thinking about is coming, not so they can pick.
     *
     * @param  Collection<int,array{lead:Lead,action:array}>  $ranked
     */
    private function queue($ranked): array
    {
        return $ranked
            ->skip(1)
            ->take(6)
            ->map(fn (array $row) => [
                'id' => $row['lead']->uuid,
                'name' => $row['lead']->full_name,
                'city' => $row['lead']->city,
                'status' => $row['lead']->status?->value,
                'temperature' => $row['lead']->temperature?->value,
                'action' => $row['action']['action'] ?? null,
                'urgency' => $row['action']['urgency'] ?? null,
                'waiting_since' => $row['lead']->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * The last few things that happened to this lead.
     *
     * Short on purpose: it answers "has anybody already tried today" before a
     * rep dials, which is the one piece of history that changes what they say
     * when the call connects.
     */
    /**
     * Why this lead, in what the customer did: replied, opened the brochure,
     * opened or clicked an email, answered the questions. The facts behind
     * the reason, as a row of chips a rep reads in a second.
     */
    private function signals(Lead $lead): array
    {
        $recent = LeadEngagement::where('lead_id', $lead->id)
            ->where('happened_at', '>=', now()->subDays(7))
            ->pluck('type')
            ->unique()
            ->all();

        return array_values(array_filter([
            $lead->first_response_at ? 'replied' : null,
            in_array(LeadEngagement::FILE_OPENED, $recent, true) ? 'brochure_opened' : null,
            in_array(LeadEngagement::LINK_CLICKED, $recent, true) ? 'email_clicked' : null,
            in_array(LeadEngagement::EMAIL_OPENED, $recent, true) ? 'email_opened' : null,
            $lead->qualification_status?->value === 'qualified' ? 'qualified' : null,
            $lead->is_priority ? 'priority' : null,
            $lead->needs_human_reason === 'no_reply' ? 'no_reply' : null,
        ]));
    }

    private function timeline(Lead $lead): array
    {
        return $lead->activities()
            ->with('user:id,name')
            ->limit(6)
            ->get()
            ->map(fn ($activity) => [
                'id' => $activity->uuid ?? (string) $activity->id,
                'type' => $activity->type?->value ?? (string) $activity->type,
                'actor' => $activity->user?->name,
                'at' => $activity->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
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

        // What the journey is handling on its own — the work nobody had to do.
        $automated = Lead::query()
            ->visibleTo($request->user())
            ->where('automation_state', Lead::AUTOMATION_NURTURING)
            ->count();

        return ApiResponse::success([
            'automated' => $automated,
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
            ->whereNotIn('status', $closed)
            /*
             * Human by exception. A lead the journey is still talking to is not
             * a rep's job yet — unless it turned hot, became a priority or
             * qualified, which is exactly when a person should step in.
             */
            ->where(fn ($q) => $q->whereNull('automation_state')
                ->orWhere('automation_state', '!=', Lead::AUTOMATION_NURTURING)
                ->orWhere('is_priority', true)
                ->orWhere('temperature', 'hot')
                ->orWhere('qualification_status', 'qualified'));
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
