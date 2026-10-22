<?php

namespace App\Domain\Conversation;

/** A fact on its way to being stored: validated, with where it came from. */
final class Fact
{
    /** @param  array<string,mixed>|null  $detail */
    public function __construct(
        public readonly string $kind,
        public readonly string $value,
        public readonly int $confidence = 60,
        public readonly ?array $detail = null,
    ) {}
}
