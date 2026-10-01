<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

class TenantSettingsPolicy
{
    public function view(User $user, Tenant $tenant): bool
    {
        return $user->belongsToTenant($tenant->id);
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $user->belongsToTenant($tenant->id) && $user->isAdmin();
    }
}
