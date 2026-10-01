<?php

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isUnscoped()) {
            return;
        }

        $tenantId = $context->id();

        // No tenant resolved => return nothing rather than everything.
        // A missing context must never widen a query.
        $builder->where(
            $model->getTable().'.tenant_id',
            $tenantId ?? -1
        );
    }
}
