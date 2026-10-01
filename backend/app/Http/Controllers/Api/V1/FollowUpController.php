<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityType;
use App\Enums\FollowUpOutcome;
use App\Http\Controllers\Controller;
use App\Http\Resources\FollowUpResource;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\ActivityLogger;
use App\Services\Leads\LeadScoringService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FollowUpController extends Controller
{
    public function __construct(
        private ActivityLogger $activities,
        private LeadScoringService $scoring,
    ) {}

    /** Buckets drive the whole follow-ups page: overdue, today, upcoming, done. */
    public function index(Request $request)
    {
        $this->authorize('viewAny', FollowUp::class);

        $bucket = $request->string('bucket', 'today')->toString();

        $query = FollowUp::query()
            ->visibleTo($request->user())
            ->with(['lead:id,uuid,full_name,phone,phone_normalized,status,temperature', 'assignee:id,uuid,name']);

        $query = match ($bucket) {
            'overdue' => $query->overdue()->orderBy('due_at'),
            'upcoming' => $query->upcoming()->orderBy('due_at'),
            'completed' => $query->where('status', FollowUp::COMPLETED)->orderByDesc('completed_at'),
            'all' => $query->orderBy('due_at'),
            default => $query->dueToday()->orderBy('due_at'),
        };

        $followUps = $query->paginate(min((int) $request->input('per_page', 50), 100))->withQueryString();

        return ApiResponse::paginated($followUps, FollowUpResource::class);
    }

    /** Counts for the sidebar badges and the dashboard. */
    public function counts(Request $request)
    {
        $this->authorize('viewAny', FollowUp::class);

        $base = fn () => FollowUp::query()->visibleTo($request->user());

        return ApiResponse::success([
            'overdue' => $base()->overdue()->count(),
            'today' => $base()->dueToday()->count(),
            'upcoming' => $base()->upcoming()->count(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', FollowUp::class);

        $data = $request->validate([
            'lead_id' => ['required', 'uuid'],
            'due_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
            'assigned_to' => ['nullable', 'uuid'],
        ]);

        $lead = Lead::where('uuid', $data['lead_id'])->firstOrFail();
        $this->authorize('update', $lead);

        $assignee = $data['assigned_to'] ?? null
            ? User::where('tenant_id', $lead->tenant_id)->where('uuid', $data['assigned_to'])->first()
            : ($lead->assignee ?? $request->user());

        if (isset($data['assigned_to']) && ! $assignee) {
            throw ValidationException::withMessages(['assigned_to' => ['This user is not available.']]);
        }

        $followUp = DB::transaction(function () use ($lead, $assignee, $request, $data) {
            $followUp = FollowUp::create([
                'lead_id' => $lead->id,
                'assigned_to' => $assignee?->id,
                'created_by' => $request->user()->id,
                'due_at' => $data['due_at'],
                'note' => $data['note'] ?? null,
            ]);

            // The lead carries the nearest pending follow-up for the table/overdue filters.
            $this->refreshLeadNextFollowUp($lead);

            $this->activities->log($lead, ActivityType::FollowUpCreated, $request->user(), $data['note'] ?? null, [
                'due_at' => $followUp->due_at->toIso8601String(),
            ]);

            return $followUp;
        });

        return ApiResponse::created([
            'follow_up' => new FollowUpResource($followUp->load('lead', 'assignee')),
        ]);
    }

    public function complete(Request $request, string $uuid)
    {
        $followUp = FollowUp::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $followUp);

        $data = $request->validate([
            'outcome' => ['nullable', Rule::in(FollowUpOutcome::values())],
            'outcome_note' => ['nullable', 'string', 'max:1000'],
            // "Call them again in three days" is the most common thing a rep
            // does next, so completing one follow-up can open the next.
            'next_due_at' => ['nullable', 'date', 'after:now'],
            'next_note' => ['nullable', 'string', 'max:1000'],
        ]);

        // Writes that must agree: a crash between them would leave the lead
        // pointing at an already-completed follow-up and skew "overdue" forever.
        DB::transaction(function () use ($followUp, $data, $request) {
            $outcome = filled($data['outcome'] ?? null) ? FollowUpOutcome::from($data['outcome']) : null;

            $followUp->update([
                'status' => FollowUp::COMPLETED,
                'completed_at' => now(),
                'outcome' => $outcome?->value,
                'outcome_note' => $data['outcome_note'] ?? null,
            ]);

            $lead = $followUp->lead;
            $lead->last_contact_at = now();
            $lead->first_contact_at ??= now();

            if ($outcome) {
                // What happened on the call is evidence about the lead, so it
                // feeds the score rather than only the follow-up's own row.
                $this->scoring->applyFollowUpOutcome($lead, $outcome);
                $before = (int) $lead->score;
                $this->scoring->apply($lead);
                $lead->qualification_status = $this->scoring->qualificationStatus($lead);
            }

            $lead->save();

            if ($outcome && isset($before) && $before !== (int) $lead->score) {
                $this->activities->log($lead, ActivityType::ScoreChanged, $request->user(), null, [
                    'from' => $before,
                    'to' => (int) $lead->score,
                    'reason' => 'follow_up_outcome',
                ]);
            }

            if (filled($data['next_due_at'] ?? null)) {
                FollowUp::create([
                    'lead_id' => $lead->id,
                    'assigned_to' => $followUp->assigned_to ?? $request->user()->id,
                    'created_by' => $request->user()->id,
                    'due_at' => $data['next_due_at'],
                    'note' => $data['next_note'] ?? null,
                    'status' => FollowUp::PENDING,
                ]);
            }

            $this->refreshLeadNextFollowUp($lead);

            $this->activities->log(
                $lead,
                $outcome ? ActivityType::FollowUpOutcomeRecorded : ActivityType::FollowUpCompleted,
                $request->user(),
                $data['outcome_note'] ?? null,
                $outcome ? ['outcome' => $outcome->value] : [],
            );
        });

        return ApiResponse::success(['follow_up' => new FollowUpResource($followUp->fresh(['lead', 'assignee']))]);
    }

    public function update(Request $request, string $uuid)
    {
        $followUp = FollowUp::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $followUp);

        $data = $request->validate([
            'due_at' => ['sometimes', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $followUp->update($data);
        $this->refreshLeadNextFollowUp($followUp->lead);

        return ApiResponse::success(['follow_up' => new FollowUpResource($followUp->fresh(['lead', 'assignee']))]);
    }

    public function destroy(Request $request, string $uuid)
    {
        $followUp = FollowUp::where('uuid', $uuid)->firstOrFail();
        $this->authorize('delete', $followUp);

        $lead = $followUp->lead;
        $followUp->update(['status' => FollowUp::CANCELLED]);
        $this->refreshLeadNextFollowUp($lead);

        return ApiResponse::success(null, 'Follow-up cancelled.');
    }

    private function refreshLeadNextFollowUp(Lead $lead): void
    {
        $next = $lead->followUps()->pending()->orderBy('due_at')->value('due_at');

        $lead->forceFill(['next_follow_up_at' => $next])->saveQuietly();
    }
}
