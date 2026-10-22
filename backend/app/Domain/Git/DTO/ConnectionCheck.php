<?php

namespace App\Domain\Git\DTO;

/**
 * Whether a connection still works, and why not when it does not.
 *
 * `reason` is a key the interface translates, never a provider message: GitHub's
 * own wording changes, is in English, and occasionally names a repository the
 * reader is not allowed to know exists.
 */
class ConnectionCheck
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $account = null,
        public readonly ?string $reason = null,
        public readonly array $scopes = [],
        public readonly ?string $tokenExpiresAt = null,
    ) {}

    public static function ok(?string $account, array $scopes = [], ?string $tokenExpiresAt = null): self
    {
        return new self(true, $account, null, $scopes, $tokenExpiresAt);
    }

    /** @param  string  $reason  one of: auth_failed, rate_limited, forbidden, unreachable */
    public static function failed(string $reason): self
    {
        return new self(false, null, $reason);
    }

    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'account' => $this->account,
            'reason' => $this->reason,
            'scopes' => $this->scopes,
            'token_expires_at' => $this->tokenExpiresAt,
        ];
    }
}
