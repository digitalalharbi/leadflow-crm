<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Every table holding customer data uses this. It does two things:
 *  1. adds a global `tenant_id = <current tenant>` scope to all reads;
 *  2. stamps tenant_id on create, and refuses to create a row with no tenant.
 *
 * Together these make cross-tenant reads and writes impossible by default — a
 * developer has to go out of their way (TenantContext::withoutScope) to opt out.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if (! empty($model->tenant_id)) {
                return;
            }

            $tenantId = app(TenantContext::class)->id();

            if ($tenantId === null) {
                // A few log tables legitimately record platform-level rows with no tenant.
                if (property_exists($model, 'tenantOptional') && $model->tenantOptional === true) {
                    return;
                }

                throw new RuntimeException(sprintf(
                    'Refusing to create %s without a tenant. Set the tenant context first.',
                    static::class
                ));
            }

            $model->tenant_id = $tenantId;
        });

        // Defence in depth: never let an update move a row to another tenant.
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
