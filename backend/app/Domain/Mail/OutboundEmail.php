<?php

namespace App\Domain\Mail;

/** An email, in the shape every provider understands: who, what, and how to tell it apart from another. */
final class OutboundEmail
{
    public function __construct(
        public readonly string $toEmail,
        public readonly ?string $toName,
        public readonly string $subject,
        public readonly string $html,
        public readonly ?string $text = null,
        public readonly string $fromAddress = '',
        public readonly string $fromName = '',
        /** Stable across retries: a provider that supports it will not send the same message twice. */
        public readonly string $idempotencyKey = '',
        /** @var array<string,string> */
        public readonly array $tags = [],
    ) {}
}
