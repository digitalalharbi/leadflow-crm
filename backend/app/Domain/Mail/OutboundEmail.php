<?php

namespace App\Domain\Mail;

/** An email, in the shape every provider understands: who, what, and how to tell it apart from another. */
final class OutboundEmail
{
    /**
     * @param  array<string,string>  $tags
     * @param  array<string,string>  $headers  extra headers by name: Message-ID, In-Reply-To, References, List-Unsubscribe…
     */
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
        public readonly array $tags = [],
        /** Where an answer goes, when it is not the sender. */
        public readonly ?string $replyTo = null,
        public readonly array $headers = [],
    ) {}

    /** The names email clients thread by; a provider sets these as id headers, everything else as text. */
    public const ID_HEADERS = ['message-id', 'in-reply-to', 'references'];
}
