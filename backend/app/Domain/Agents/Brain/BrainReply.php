<?php

namespace App\Domain\Agents\Brain;

/** One model turn: what it said, which tools it wants, and what it cost. */
final class BrainReply
{
    /**
     * @param  list<array{id: string, name: string, input: array<string, mixed>}>  $toolCalls
     * @param  list<array<string, mixed>>  $content  the raw content blocks, to send back on the next turn
     * @param  array{input_tokens?: int, output_tokens?: int}  $usage
     */
    public function __construct(
        public readonly string $text,
        public readonly array $toolCalls,
        public readonly array $content,
        public readonly string $stopReason,
        public readonly array $usage = [],
        public readonly ?string $model = null,
    ) {}
}
