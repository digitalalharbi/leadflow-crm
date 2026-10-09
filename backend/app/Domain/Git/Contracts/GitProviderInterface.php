<?php

namespace App\Domain\Git\Contracts;

use App\Domain\Git\DTO\ConnectionCheck;
use App\Domain\Git\DTO\RepositoryDiscovery;
use App\Domain\Git\DTO\RepositorySummary;
use App\Models\Integration;

/**
 * What مهتم needs from a code host, and nothing more.
 *
 * Written as an interface because GitLab and Bitbucket are the same questions
 * asked of a different API — "which repositories may I see", "what is this one
 * called and what does it branch from", "is this connection still good". The
 * domain and the controllers speak only this, so adding a second host is a
 * class, not a rewrite.
 *
 * Deliberately small. It is tempting to mirror the GitHub API; that way the
 * interface becomes GitHub's shape and the second provider has to pretend. Each
 * method here exists because a screen in مهتم needs the answer.
 */
interface GitProviderInterface
{
    /** The stored key — `github`, later `gitlab`. */
    public function key(): string;

    /**
     * Whether the credentials still work, and who they belong to.
     *
     * Used by Connect, by Test Connection and by the health centre, so it has
     * to be cheap: one request, no pagination, and it must never throw — a
     * connection that is broken is an answer, not an exception.
     */
    public function check(Integration $connection): ConnectionCheck;

    /**
     * The repositories this connection may actually see.
     *
     * @return array<int,RepositorySummary>
     */
    public function repositories(Integration $connection, ?string $search = null): array;

    /**
     * Everything needed to fill in a mapping without asking the user to type
     * it: the default branch, which branches exist, which workflows run, and
     * what was last released.
     */
    public function discover(Integration $connection, string $fullName): RepositoryDiscovery;
}
