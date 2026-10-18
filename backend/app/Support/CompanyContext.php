<?php

namespace App\Support;

/**
 * Which companies this request may see, and which one it is looking at.
 *
 * Two different things, kept apart on purpose:
 *
 *   allowed   what the person is *permitted* — null means every company (the
 *             default, and every admin), a list means exactly those
 *   selected  what they have *chosen* to look at through the switcher
 *
 * The effective filter is the selection when there is one, otherwise what is
 * allowed. Permission is never widened by a choice, and a choice outside the
 * permitted set is refused by the middleware before it gets here.
 *
 * Like the tenant context it is empty outside a request — jobs, console and
 * ingestion see everything the tenant scope allows — so nothing that runs
 * without a person ever finds itself filtered by a person's switcher.
 */
class CompanyContext
{
    /** @var array<int,int>|null */
    protected ?array $allowed = null;

    protected ?int $selected = null;

    /** @param array<int,int>|null $ids */
    public function restrictTo(?array $ids): void
    {
        $this->allowed = $ids;
    }

    public function select(?int $companyId): void
    {
        $this->selected = $companyId;
    }

    /** @return array<int,int>|null  null = no filter at all */
    public function ids(): ?array
    {
        if ($this->selected !== null) {
            return [$this->selected];
        }

        return $this->allowed;
    }

    /** @return array<int,int>|null */
    public function allowed(): ?array
    {
        return $this->allowed;
    }

    public function selected(): ?int
    {
        return $this->selected;
    }

    public function clear(): void
    {
        $this->allowed = null;
        $this->selected = null;
    }

    /** Look at everything this person may see, ignoring the switcher. */
    public function withoutSelection(callable $callback): mixed
    {
        $previous = $this->selected;
        $this->selected = null;

        try {
            return $callback();
        } finally {
            $this->selected = $previous;
        }
    }
}
