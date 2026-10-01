<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Single source of truth for "which tenant is this request acting as".
 *
 * Everything that touches tenant data reads from here: the BelongsToTenant global
 * scope, policies, and the ingestion pipeline. Nothing else is allowed to guess a
 * tenant id from the request.
 */
class TenantContext
{
    protected ?Tenant $tenant = null;

    /** When true the global scope is bypassed (super admin / console / ingestion bootstrap). */
    protected bool $unscoped = false;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function isUnscoped(): bool
    {
        return $this->unscoped;
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->unscoped = false;
    }

    /**
     * Run a callback with the global tenant scope disabled.
     * Reserved for super-admin reads and system jobs that span tenants.
     */
    public function withoutScope(callable $callback): mixed
    {
        $previous = $this->unscoped;
        $this->unscoped = true;

        try {
            return $callback();
        } finally {
            $this->unscoped = $previous;
        }
    }

    /** Run a callback as a specific tenant (queue jobs, ingestion, seeding). */
    public function withTenant(Tenant $tenant, callable $callback): mixed
    {
        $previousTenant = $this->tenant;
        $previousUnscoped = $this->unscoped;

        $this->tenant = $tenant;
        $this->unscoped = false;

        try {
            return $callback();
        } finally {
            $this->tenant = $previousTenant;
            $this->unscoped = $previousUnscoped;
        }
    }
}
