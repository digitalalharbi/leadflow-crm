<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityType;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeadResource;
use App\Models\Lead;
use App\Services\Leads\ActivityLogger;
use App\Services\Leads\LeadScoringService;
use App\Support\ApiResponse;
use App\Support\Qualification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Qualifying a lead: the eight answers that decide whether this is a buyer.
 *
 * Kept as its own endpoint rather than folded into the lead update, because
 * saving it does more than store fields — it re-scores the lead, may move it
 * between hot, warm and cold, and writes the trail that explains why.
 */
class QualificationController extends Controller
{
    public function __construct(
        private LeadScoringService $scoring,
        private ActivityLogger $activities,
    ) {}

    /** The option lists, so the form never hard-codes them in the client. */
    public function options()
    {
        return ApiResponse::success(Qualification::options());
    }

    public function update(Request $request, string $uuid)
    {
        $lead = Lead::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $lead);

        $data = $request->validate([
            'purchase_purpose' => ['nullable', Rule::in(Qualification::PURPOSES)],
            'budget_min' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            // `gte:budget_min` on its own rejects a maximum stated without a
            // minimum, which is how most people answer "what is your budget?".
            'budget_max' => ['nullable', 'numeric', 'min:0', 'max:999999999999',
                Rule::when(fn () => filled($request->input('budget_min')), ['gte:budget_min'])],
            'payment_method' => ['nullable', Rule::in(Qualification::PAYMENT_METHODS)],
            'financing_status' => ['nullable', Rule::in(Qualification::FINANCING_STATUSES)],
            'preferred_property_type' => ['nullable', 'string', 'max:120'],
            'preferred_location' => ['nullable', 'string', 'max:255'],
            'purchase_timeline' => ['nullable', Rule::in(Qualification::TIMELINES)],
            'appointment_interest' => ['nullable', 'boolean'],
        ]);

        $before = [
            'score' => (int) $lead->score,
            'temperature' => $lead->temperature,
            'qualification_status' => $lead->qualification_status,
        ];

        DB::transaction(function () use ($lead, $data, $request, $before) {
            $lead->fill($data);

            // Scoring reads the project's price band, so the relation has to be
            // the current one — not whatever was loaded before the fill.
            $lead->load('project', 'lostReason');

            $this->scoring->apply($lead);
            $lead->qualification_status = $this->scoring->qualificationStatus($lead);

            if ($lead->qualification_status->value === 'qualified' && $lead->qualified_at === null) {
                $lead->qualified_at = now();
            }

            $lead->save();

            $this->activities->log(
                $lead,
                ActivityType::QualificationUpdated,
                $request->user(),
                null,
                ['fields' => array_keys(array_filter($data, fn ($v) => $v !== null))],
            );

            if ($before['score'] !== (int) $lead->score) {
                $this->activities->log($lead, ActivityType::ScoreChanged, $request->user(), null, [
                    'from' => $before['score'],
                    'to' => (int) $lead->score,
                    'temperature' => $lead->temperature->value,
                    'reason' => 'qualification',
                ]);
            }
        });

        $lead->refresh()->load(['assignee:id,uuid,name', 'project:id,uuid,name', 'lostReason:id,uuid,label']);

        return ApiResponse::success([
            'lead' => new LeadResource($lead),
            'scoring' => $this->scoring->evaluate($lead),
            'changed' => [
                'score' => $before['score'] !== (int) $lead->score,
                'temperature' => $before['temperature'] !== $lead->temperature,
                'qualification_status' => $before['qualification_status'] !== $lead->qualification_status,
            ],
        ], 'Saved.');
    }

    /** Why this lead scores what it scores, for the card that shows it. */
    public function show(Request $request, string $uuid)
    {
        $lead = Lead::where('uuid', $uuid)->with('project', 'lostReason')->firstOrFail();
        $this->authorize('view', $lead);

        return ApiResponse::success($this->scoring->evaluate($lead));
    }
}
