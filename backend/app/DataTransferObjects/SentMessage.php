<?php

namespace App\DataTransferObjects;

/**
 * What a provider says happened when we asked it to send something.
 */
class SentMessage
{
    private function __construct(
        public readonly bool $accepted,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureReason = null,
        public readonly array $payload = [],
    ) {}

    public static function accepted(string $providerMessageId, array $payload = []): self
    {
        return new self(true, $providerMessageId, payload: $payload);
    }

    public static function failed(string $code, string $reason, array $payload = []): self
    {
        return new self(false, failureCode: $code, failureReason: $reason, payload: $payload);
    }
}
