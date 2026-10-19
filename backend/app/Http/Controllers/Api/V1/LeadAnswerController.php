<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Leads\StageAnnouncer;
use App\Domain\Qualification\ProjectQualification;
use App\Enums\ActivityType;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadAnswer;
use App\Services\Leads\ActivityLogger;
use App\Services\Leads\LeadScoringService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A lead's answers to its project's own questions.
 *
 * Saving does what saving the standard qualification does: re-scores the lead,
 * may move it between hot, warm and cold or make it a priority, and writes the
 * trail that says why. An answer that does not fit its question is refused with
 * the question named — a number typed into a yes/no is a mistake somebody wants
 * to be told about, not a value to be quietly coerced.
 */
class LeadAnswerController extends Controller
{
    public function __construct(
        private ProjectQualification $qualification,
        private LeadScoringService $scoring,
        private ActivityLogger $activities,
    ) {}

    public function show(string $uuid)
    {
        $lead = Lead::where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $lead);

        return ApiResponse::success($this->payload($lead));
    }

    public function update(Request $request, string $uuid)
    {
        $lead = Lead::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $lead);

        $given = $request->validate(['answers' => ['required', 'array', 'max:50']])['answers'];

        $questions = $this->qualification->questionsFor($lead)->keyBy('key');
        $clean = [];
        $errors = [];

        foreach ($given as $key => $raw) {
            $question = $questions[$key] ?? null;

            if (! $question) {
                $errors["answers.$key"] = ['This project does not ask that.'];

                continue;
            }

            try {
                $clean[$question->id] = $this->qualification->normalize($question, $raw);
            } catch (InvalidArgumentException $e) {
                $errors["answers.$key"] = [$e->getMessage()];
            }
        }

        if ($errors !== []) {
            return ApiResponse::error('Some answers were not accepted.', $errors, 422);
        }

        $before = ['score' => (int) $lead->score, 'priority' => (bool) $lead->is_priority];

        $qualificationBefore = $lead->qualification_status?->value;

        DB::transaction(function () use ($lead, $clean, $request, $before) {
            foreach ($clean as $questionId => $value) {
                if ($value === null) {
                    LeadAnswer::where('lead_id', $lead->id)->where('project_question_id', $questionId)->delete();

                    continue;
                }

                LeadAnswer::updateOrCreate(
                    ['lead_id' => $lead->id, 'project_question_id' => $questionId],
                    ['value' => $value],
                );
            }

            $this->scoring->apply($lead);
            $lead->qualification_status = $this->scoring->qualificationStatus($lead);
            $lead->save();

            $this->activities->log($lead, ActivityType::QualificationUpdated, $request->user(), null, [
                'fields' => array_map(fn ($id) => $id, array_keys($clean)),
                'source' => 'project_questions',
            ]);

            if ($before['score'] !== (int) $lead->score || $before['priority'] !== (bool) $lead->is_priority) {
                $this->activities->log($lead, ActivityType::ScoreChanged, $request->user(), null, [
                    'from' => $before['score'],
                    'to' => (int) $lead->score,
                    'temperature' => $lead->temperature->value,
                    'priority' => (bool) $lead->is_priority,
                    'reason' => 'project_questions',
                ]);
            }
        });

        app(StageAnnouncer::class)->qualifiedByAnswers($lead, $qualificationBefore, $request->user());

        return ApiResponse::success($this->payload($lead->refresh()));
    }

    /** @return array<string,mixed> */
    private function payload(Lead $lead): array
    {
        $questions = $this->qualification->questionsFor($lead);
        $answers = LeadAnswer::where('lead_id', $lead->id)->get()->keyBy('project_question_id');

        return [
            'questions' => $questions->map(fn ($q) => [
                'id' => $q->uuid,
                'key' => $q->key,
                'label' => $q->label,
                'type' => $q->type,
                'options' => $q->options ?? [],
                'is_required' => $q->is_required,
                'answer' => $answers[$q->id]->value ?? null,
            ])->values(),
            'is_priority' => (bool) $lead->is_priority,
            'wrong_fit' => (bool) $lead->wrong_fit,
            'score' => (int) $lead->score,
            'qualification_status' => $lead->qualification_status?->value,
        ];
    }
}
