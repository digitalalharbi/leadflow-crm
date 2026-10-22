<?php

namespace App\Domain\Mail;

/** What a provider said: sent (with its own id for the message), or not — and whether trying again could help. */
final class DeliveryResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $messageId,
        public readonly ?string $error,
        public readonly bool $retryable,
        /** The provider wrote it down instead of sending it: not the same as delivered. */
        public readonly bool $simulated = false,
    ) {}

    public static function sent(?string $messageId): self
    {
        return new self(true, $messageId, null, false);
    }

    public static function logged(string $messageId): self
    {
        return new self(true, $messageId, null, false, true);
    }

    public static function failed(string $error, bool $retryable): self
    {
        return new self(false, null, mb_substr($error, 0, 1000), $retryable);
    }
}
