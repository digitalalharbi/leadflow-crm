<?php

namespace App\Support;

/**
 * Which projects this request may see — null means all of them.
 *
 * Set only for the roles that are scoped to projects (a project manager, a client, a broker),
 * from the projects they were given, and empty outside a request: a job or the console is never
 * narrowed by whoever last used the connection. The same shape as the company context, for the
 * same reason — a filter every query has to remember is a filter one query forgets.
 */
class ProjectContext
{
    /** @var array<int,int>|null */
    protected ?array $ids = null;

    /** @param array<int,int>|null $ids */
    public function restrictTo(?array $ids): void
    {
        $this->ids = $ids;
    }

    /** The partner a broker is — their leads and links, and nobody else's. Null for everyone else. */
    protected ?int $partnerId = null;

    public function restrictToPartner(?int $partnerId): void
    {
        $this->partnerId = $partnerId;
    }

    public function partnerId(): ?int
    {
        return $this->partnerId;
    }

    /** @return array<int,int>|null */
    public function ids(): ?array
    {
        return $this->ids;
    }

    public function clear(): void
    {
        $this->ids = null;
        $this->partnerId = null;
    }
}
