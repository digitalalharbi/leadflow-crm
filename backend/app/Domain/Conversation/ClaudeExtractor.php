<?php

namespace App\Domain\Conversation;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Claude reading a conversation — held to the same closed lists as the rules.
 *
 * It is asked for exactly one tool call whose schema is those lists, and
 * whatever comes back is validated against them again: a value that is not on
 * its list is dropped, an amount is clamped to sane bounds, and nothing free-
 * form is kept. If the call fails or returns nothing usable, the caller falls
 * back to the rules and says so.
 */
final class ClaudeExtractor
{
    public function __construct(private string $apiKey) {}

    /**
     * @param  list<array{who:string, text:string}>  $turns  oldest first; who is "customer" or "us"
     * @return list<Fact>
     *
     * @throws RuntimeException
     */
    public function extract(array $turns): array
    {
        $config = config('services.anthropic');

        $transcript = collect($turns)->map(fn (array $t) => $t['who'].': '.mb_substr($t['text'], 0, 600))->implode("\n");

        $response = Http::withHeaders(['x-api-key' => $this->apiKey, 'anthropic-version' => $config['version']])
            ->acceptJson()->timeout(min((int) $config['timeout'], 25))
            ->post(rtrim($config['base_url'], '/').'/v1/messages', [
                'model' => $config['models']['leads'] ?? $config['models']['analyst'],
                'max_tokens' => 512,
                'system' => 'You read a real-estate sales conversation in Arabic or English and record only what the CUSTOMER expressed. Omit anything not stated. Never invent an amount. Call record_facts exactly once.',
                'messages' => [['role' => 'user', 'content' => $transcript]],
                'tools' => [$this->tool()],
                'tool_choice' => ['type' => 'tool', 'name' => 'record_facts'],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Claude API error '.$response->status());
        }

        $input = collect($response->json('content') ?? [])->firstWhere('type', 'tool_use')['input'] ?? null;

        if (! is_array($input)) {
            throw new RuntimeException('Claude returned no facts.');
        }

        return $this->validated($input);
    }

    /** @return array<string,mixed> */
    private function tool(): array
    {
        $enum = fn (string $kind) => ['type' => 'string', 'enum' => Vocabulary::VALUES[$kind]];

        return [
            'name' => 'record_facts',
            'description' => 'Record the structured facts the customer expressed. Omit any field they did not.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'intent' => $enum(Vocabulary::INTENT),
                    'urgency' => $enum(Vocabulary::URGENCY),
                    'timeline' => ['type' => 'string', 'enum' => Vocabulary::TIMELINES],
                    'service_interest' => $enum(Vocabulary::SERVICE),
                    'appointment_intent' => $enum(Vocabulary::APPOINTMENT),
                    'sentiment' => $enum(Vocabulary::SENTIMENT),
                    'budget' => ['type' => 'object', 'properties' => [
                        'signal' => $enum(Vocabulary::BUDGET), 'min' => ['type' => 'integer'], 'max' => ['type' => 'integer'],
                    ]],
                    'objections' => ['type' => 'array', 'items' => $enum(Vocabulary::OBJECTION)],
                    'transaction' => $enum(Vocabulary::TRANSACTION),
                    'payment' => $enum(Vocabulary::PAYMENT),
                    'purpose' => $enum(Vocabulary::PURPOSE),
                    'reservation_intent' => $enum(Vocabulary::RESERVATION),
                    'bedrooms' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
                    'district' => ['type' => 'string', 'description' => 'Only the neighbourhood name, nothing else.'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<Fact>
     */
    private function validated(array $input): array
    {
        $facts = [];
        $add = function (string $kind, mixed $value, ?array $detail = null) use (&$facts) {
            if (is_string($value) && Vocabulary::valid($kind, $value)) {
                $facts[] = new Fact($kind, $value, 80, $detail);
            }
        };

        $timeline = in_array($input['timeline'] ?? null, Vocabulary::TIMELINES, true) ? ['timeline' => $input['timeline']] : null;
        $add(Vocabulary::INTENT, $input['intent'] ?? null);
        $add(Vocabulary::URGENCY, $input['urgency'] ?? null, $timeline);
        $add(Vocabulary::SERVICE, $input['service_interest'] ?? null);
        $add(Vocabulary::APPOINTMENT, $input['appointment_intent'] ?? null);
        $add(Vocabulary::SENTIMENT, $input['sentiment'] ?? null);
        $add(Vocabulary::TRANSACTION, $input['transaction'] ?? null);
        $add(Vocabulary::PAYMENT, $input['payment'] ?? null);
        $add(Vocabulary::PURPOSE, $input['purpose'] ?? null);
        $add(Vocabulary::RESERVATION, $input['reservation_intent'] ?? null);

        // Free-valued facts are held to their shape, not a list.
        if (is_numeric($input['bedrooms'] ?? null)) {
            $add(Vocabulary::BEDROOMS, (string) (int) $input['bedrooms']);
        }
        if (is_string($input['district'] ?? null)) {
            $add(Vocabulary::DISTRICT, trim($input['district']));
        }

        $budget = (array) ($input['budget'] ?? []);
        if (is_string($budget['signal'] ?? null) && Vocabulary::valid(Vocabulary::BUDGET, $budget['signal'])) {
            $min = is_numeric($budget['min'] ?? null) ? (int) $budget['min'] : null;
            $max = is_numeric($budget['max'] ?? null) ? (int) $budget['max'] : $min;
            $sane = fn (?int $n) => $n !== null && $n >= 10_000 && $n <= 1_000_000_000;
            $detail = $budget['signal'] === 'stated' && $sane($min) && $sane($max) ? ['min' => min($min, $max), 'max' => max($min, $max), 'currency' => 'SAR'] : null;

            // A "stated" budget without a believable amount is not a stated budget.
            if ($budget['signal'] !== 'stated' || $detail !== null) {
                $facts[] = new Fact(Vocabulary::BUDGET, $budget['signal'], 80, $detail);
            }
        }

        foreach ((array) ($input['objections'] ?? []) as $objection) {
            $add(Vocabulary::OBJECTION, $objection);
        }

        return $facts;
    }
}
