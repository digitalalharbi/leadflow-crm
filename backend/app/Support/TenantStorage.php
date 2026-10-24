<?php

namespace App\Support;

/**
 * Where a company's files live: under its own prefix on the private disk, `tenant/{id}/…`. Nothing a company uploads is
 * stored where another company's path could reach it, and nothing on this disk is served without a check first —
 * a signed, expiring link for staff, a per-lead share token for a customer.
 */
class TenantStorage
{
    public static function dir(int $tenantId, string ...$parts): string
    {
        return 'tenant/'.$tenantId.($parts === [] ? '' : '/'.implode('/', array_map(fn ($p) => trim($p, '/'), $parts)));
    }

    /** Whether a stored path belongs to this company (new paths) — legacy paths are checked by their row instead. */
    public static function owns(int $tenantId, string $path): bool
    {
        return ! str_starts_with($path, 'tenant/') || str_starts_with($path, 'tenant/'.$tenantId.'/');
    }
}
