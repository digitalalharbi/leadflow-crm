<?php

namespace App\Domain\Leads;

use App\Domain\Conversation\ConversationIntelligence;
use App\Domain\Qualification\ProjectQualification;
use App\Models\Lead;
use App\Models\LeadAnswer;
use App\Models\LeadMessage;
use App\Models\ProjectQuestion;
use Illuminate\Support\Str;

/**
 * Everything a rep needs in the first ten seconds after automation hands them
 * a lead: who this is, why it is theirs now, what is already known and what is
 * not, what is holding the customer back, what was last said, and what to do.
 *
 * Assembled from what the product already holds — nothing is written, nothing
 * asked of the rep. Short by construction: a handful of fields and five lines
 * of conversation, because a brief that has to be read is a brief that is not
 * read.
 */
class HandoffBrief
{
    /** @param  array{action:string,urgency:string,reason:string}|null  $next */
    public function for(Lead $lead, ?array $next = null): array
    {
        $conversation = app(ConversationIntelligence::class)->summary($lead);
        $questions = app(ProjectQualification::class)->questionsFor($lead);
        $answers = LeadAnswer::where('lead_id', $lead->id)->get()->keyBy('project_question_id');

        $answered = $questions->filter(fn (ProjectQuestion $q) => $answers->has($q->id))->map(fn (ProjectQuestion $q) => [
            'label' => $q->label, 'value' => $this->shown($q, $answers[$q->id]->value),
        ])->values();

        return [
            'reason' => ['code' => $lead->needs_human_reason, 'words' => Lead::handOffWords($lead->needs_human_reason)],
            'since' => $lead->handed_off_at?->toIso8601String(),
            'summary' => [
                'project' => $lead->relationLoaded('project') ? $lead->project?->name : null,
                'city' => $lead->city,
                'platform' => $lead->platform,
                'campaign' => $lead->campaign_name,
                'budget' => array_filter(['min' => $lead->budget_min !== null ? (float) $lead->budget_min : null, 'max' => $lead->budget_max !== null ? (float) $lead->budget_max : null], fn ($v) => $v !== null) ?: null,
                'timeline' => $lead->purchase_timeline,
                'property' => $lead->preferred_property_type,
                'score' => (int) $lead->score,
                'temperature' => $lead->temperature?->value,
                'priority' => (bool) $lead->is_priority,
            ],
            'why_now' => [
                'signals' => WhyNow::signals($lead),
                'said' => $lead->relationLoaded('intelligence') ? ($lead->getRelation('intelligence')['reason'] ?? null) : null,
            ],
            'qualification' => [
                'status' => $lead->qualification_status?->value,
                'done' => $answered->count(),
                'total' => $questions->count(),
                'answered' => $answered->take(6)->all(),
                'missing' => $questions->reject(fn (ProjectQuestion $q) => $answers->has($q->id))->pluck('label')->take(4)->values()->all(),
            ],
            'objections' => $conversation['facts']['objections'],
            'mood' => ['intent' => $conversation['facts']['intent']['value'] ?? null, 'urgency' => $conversation['facts']['urgency']['value'] ?? null, 'sentiment' => $conversation['facts']['sentiment'] ?? null],
            'engine' => $conversation['engine'],
            'last_conversation' => $this->lastWords($lead),
            'interest' => ['level' => $lead->interest_level, 'sla' => ($sla = InterestSla::for($lead)) ? ['state' => $sla['state'], 'minutes_left' => $sla['minutes_left']] : null],
            'last_activity' => $this->lastActivity($lead),
            'next_action' => $next === null ? null : ['action' => $next['action'], 'urgency' => $next['urgency'], 'reason' => $next['reason']],
        ];
    }

    /** Who spoke last, and when: the latest message either way, or the last time somebody reached them. @return array{who:string,at:?string}|null */
    private function lastActivity(Lead $lead): ?array
    {
        $m = LeadMessage::withoutGlobalScopes()->where('lead_id', $lead->id)->latest('id')->first(['direction', 'user_id', 'created_at']);

        if ($m !== null) {
            return ['who' => $m->direction->value === 'inbound' ? 'customer' : ($m->user_id ? 'rep' : 'automation'), 'at' => $m->created_at?->toIso8601String()];
        }

        return $lead->last_contact_at ? ['who' => 'rep', 'at' => $lead->last_contact_at->toIso8601String()] : null;
    }

    /** The last five lines, oldest first, each short. */
    private function lastWords(Lead $lead): array
    {
        return LeadMessage::withoutGlobalScopes()->where('lead_id', $lead->id)->whereNotNull('body')->orderByDesc('id')->limit(5)->get()->reverse()->values()
            ->map(fn (LeadMessage $m) => [
                'who' => $m->direction->value === 'inbound' ? 'customer' : ($m->user_id ? 'rep' : 'automation'),
                'channel' => $m->channel->value,
                'text' => Str::limit(trim((string) $m->body), 140),
                'at' => $m->created_at?->toIso8601String(),
            ])->all();
    }

    private function shown(ProjectQuestion $question, mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'نعم' : 'لا';
        }

        if ($question->type === 'select') {
            foreach ((array) $question->options as $option) {
                if (($option['value'] ?? null) === $value) {
                    return (string) ($option['label'] ?? $value);
                }
            }
        }

        return Str::limit(is_scalar($value) ? (string) $value : json_encode($value), 60);
    }
}
