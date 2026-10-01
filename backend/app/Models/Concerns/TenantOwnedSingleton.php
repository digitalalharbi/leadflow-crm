<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * For a tenant's one-to-one configuration records (branding, settings).
 *
 * These deliberately do NOT carry the global read scope. They are only ever
 * reached through `$tenant->branding` / `$tenant->settings`, which are already
 * constrained by tenant_id — so the scope adds nothing, and it actively breaks
 * the one case that matters: the login screen has to render a customer's logo
 * and colours *before* anyone is authenticated, i.e. before a tenant context
 * exists. With the scope applied, that read silently returned null.
 *
 * Writes are still stamped and tenant_id is still immutable.
 */
trait TenantOwnedSingleton
{
    public static function bootTenantOwnedSingleton(): void
    {
        static::creating(function ($model) {
            if (! empty($model->tenant_id)) {
                return;
            }

            $tenantId = app(TenantContext::class)->id();

            if ($tenantId === null) {
                throw new RuntimeException(sprintf(
                    'Refusing to create %s without a tenant.', static::class
                ));
            }

            $model->tenant_id = $tenantId;
        });

        static::updating(function ($model) {
            if ($model->isDirty('tenant_id')) {
                throw new RuntimeException('tenant_id is immutable.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
