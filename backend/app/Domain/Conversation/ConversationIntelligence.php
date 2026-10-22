<?php

namespace App\Domain\Conversation;

use App\Domain\Leads\InterestEngine;
use App\Enums\MessageDirection;
use App\Models\Lead;
use App\Models\LeadFact;
use App\Models\LeadMessage;
use App\Models\TenantSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reads a lead's conversation and keeps what it says as structured facts.
 *
 * Claude when the workspace (or the platform) has a key; the rules when it has
 * not, and when Claude fails — and the result says which, and why, every time,
 * so a screen never presents a keyword match as a model's reading.
 *
 * Only the customer's own messages feed the rules. Claude sees both sides so
 * it can tell what an answer was an answer to. What is kept are the facts, not
 * the words: each points at the message it came from.
 */
class ConversationIntelligence
{
    /** How much of the conversation is read. */
    private const MESSAGES = 20;

    public function __construct(private QualificationCompleter $completer) {}

    /**
     * @return array{engine:?string, note:?string, facts:int, skipped:bool}
     */
    public function analyse(Lead $lead, bool $force = false): array
    {
        $messages = LeadMessage::withoutGlobalScopes()->where('lead_id', $lead->id)->whereNotNull('body')
            ->orderByDesc('id')->limit(self::MESSAGES)->get()->reverse()->values();
        $inbound = $messages->filter(fn (LeadMessage $m) => $this->isCustomer($m))->values();
        $latest = $inbound->last();

        if (! $latest) {
            return ['engine' => $lead->facts_engine, 'note' => $lead->facts_note, 'facts' => 0, 'skipped' => true];
        }

        // Once per customer message: a burst of replies reads the conversation once, not five times.
        if (! $force && (int) $lead->facts_message_id === $latest->id) {
            return ['engine' => $lead->facts_engine, 'note' => $lead->facts_note, 'facts' => 0, 'skipped' => true];
        }

        [$facts, $engine, $note] = $this->read($lead, $messages, $inbound);

        DB::transaction(function () use ($lead, $facts, $engine, $note, $latest) {
            foreach ($facts as $fact) {
                // One current value for the single-valued kinds; objections accumulate.
                if (in_array($fact->kind, Vocabulary::SINGLE, true)) {
                    LeadFact::where('lead_id', $lead->id)->where('kind', $fact->kind)->where('value', '!=', $fact->value)->delete();
                }

                LeadFact::updateOrCreate(
                    ['lead_id' => $lead->id, 'kind' => $fact->kind, 'value' => $fact->value],
                    ['detail' => $fact->detail, 'confidence' => $fact->confidence, 'source' => $engine, 'message_id' => $latest->id, 'extracted_at' => now()],
                );
            }

            $lead->forceFill(['facts_message_id' => $latest->id, 'facts_engine' => $engine, 'facts_note' => $note, 'facts_at' => now()])->saveQuietly();
        });

        $stored = LeadFact::where('lead_id', $lead->id)->get();

        // What the customer told us becomes what we know — and is never asked again.
        $this->completer->fromReply($lead, $latest);
        $this->completer->applyFacts($lead->refresh(), $stored);
        $this->completer->syncAnswers($lead->refresh());

        // What they asked and what they want change how interested they are.
        InterestEngine::touch($lead->id, 'conversation');

        return ['engine' => $engine, 'note' => $note, 'facts' => count($facts), 'skipped' => false];
    }

    /**
     * @param  Collection<int,LeadMessage>  $messages
     * @param  Collection<int,LeadMessage>  $inbound
     * @return array{0:list<Fact>, 1:string, 2:?string}
     */
    private function read(Lead $lead, Collection $messages, Collection $inbound): array
    {
        $key = $this->apiKey($lead);

        if ($key === null) {
            return [(new RulesExtractor)->extract($inbound->pluck('body')->all()), 'rules', 'no_api_key'];
        }

        try {
            $turns = $messages->map(fn (LeadMessage $m) => ['who' => $this->isCustomer($m) ? 'customer' : 'us', 'text' => (string) $m->body])->all();
            $facts = (new ClaudeExtractor($key))->extract($turns);

            if ($facts !== []) {
                return [$facts, 'claude', null];
            }

            // Valid, and nothing usable in it: the rules still have a say.
            return [(new RulesExtractor)->extract($inbound->pluck('body')->all()), 'rules', 'claude_empty'];
        } catch (\Throwable $e) {
            Log::warning('conversation_claude_failed', ['lead' => $lead->id, 'error' => $e->getMessage()]);

            return [(new RulesExtractor)->extract($inbound->pluck('body')->all()), 'rules', 'claude_failed'];
        }
    }

    private function apiKey(Lead $lead): ?string
    {
        $own = TenantSetting::withoutGlobalScopes()->where('tenant_id', $lead->tenant_id)->first()?->ai_api_key;
        $key = $own ?: config('services.anthropic.key');

        return filled($key) ? (string) $key : null;
    }

    private function isCustomer(LeadMessage $message): bool
    {
        $direction = $message->direction instanceof MessageDirection ? $message->direction->value : $message->direction;

        return $direction === MessageDirection::Inbound->value;
    }

    /**
     * What a screen shows: the current facts, which engine read them, and why.
     *
     * @return array<string,mixed>
     */
    public function summary(Lead $lead): array
    {
        $facts = LeadFact::where('lead_id', $lead->id)->get();
        $one = fn (string $kind) => $facts->firstWhere('kind', $kind);

        return [
            'engine' => $lead->facts_engine,
            // Why it is the rules and not Claude: no key, or Claude failed.
            'note' => $lead->facts_note,
            'analyzed_at' => $lead->facts_at?->toIso8601String(),
            'facts' => [
                'intent' => $one(Vocabulary::INTENT)?->only(['value', 'confidence']),
                'urgency' => $one(Vocabulary::URGENCY) ? ['value' => $one(Vocabulary::URGENCY)->value, 'timeline' => $one(Vocabulary::URGENCY)->detail['timeline'] ?? null] : null,
                'service_interest' => $one(Vocabulary::SERVICE)?->value,
                'budget' => $one(Vocabulary::BUDGET) ? ['signal' => $one(Vocabulary::BUDGET)->value] + (array) $one(Vocabulary::BUDGET)->detail : null,
                'appointment_intent' => $one(Vocabulary::APPOINTMENT)?->value,
                'sentiment' => $one(Vocabulary::SENTIMENT)?->value,
                'transaction' => $one(Vocabulary::TRANSACTION)?->value,
                'payment' => $one(Vocabulary::PAYMENT)?->value,
                'purpose' => $one(Vocabulary::PURPOSE)?->value,
                'reservation_intent' => $one(Vocabulary::RESERVATION)?->value,
                'bedrooms' => $one(Vocabulary::BEDROOMS) ? (int) $one(Vocabulary::BEDROOMS)->value : null,
                'district' => $one(Vocabulary::DISTRICT)?->value,
                'objections' => $facts->where('kind', Vocabulary::OBJECTION)->pluck('value')->values()->all(),
            ],
        ];
    }
}
