<?php

namespace App\Domain\Git\DTO;

/**
 * Whether مهتم managed to set up its own webhook on a repository.
 *
 * `manual` is the state that matters. A token granted only read access cannot
 * create a webhook, and that is a reasonable thing for a careful workspace to
 * do — so the answer is not an error, it is "you will have to add this one
 * yourself", with the URL and secret to add.
 */
class WebhookState
{
    private function __construct(
        public readonly string $status,
        public readonly ?int $id = null,
        public readonly ?string $reason = null,
    ) {}

    public static function created(int $id): self
    {
        return new self('ok', $id);
    }

    /** Already there, ours, and pointing at the right place. */
    public static function existing(int $id): self
    {
        return new self('ok', $id);
    }

    /** The token may read the repository but not manage its hooks. */
    public static function manual(string $reason = 'forbidden'): self
    {
        return new self('manual', null, $reason);
    }

    public static function failed(string $reason): self
    {
        return new self('failed', null, $reason);
    }
}
