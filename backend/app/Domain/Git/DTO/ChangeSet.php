<?php

namespace App\Domain\Git\DTO;

/**
 * What a sweep found, and whether it managed to look everywhere.
 *
 * The error matters more than the changes. A sweep that could not read the
 * pull requests still found some workflow runs, and advancing the cursor on
 * that would skip the window it failed to read — forever, silently, because
 * the next sweep starts after it. So a partial read is a failed read as far as
 * the cursor is concerned.
 */
class ChangeSet
{
    public function __construct(
        /** @var array<int,array{event:string,payload:array}> */
        public readonly array $changes = [],
        public readonly ?string $error = null,
    ) {}

    public function complete(): bool
    {
        return $this->error === null;
    }
}
