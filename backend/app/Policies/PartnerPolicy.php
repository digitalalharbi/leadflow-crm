<?php

namespace App\Policies;

use App\Models\Partner;
use App\Models\User;

class PartnerPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isSuperAdmin() && ($user->isAdmin() || $user->isManager());
    }

    public function view(User $user, Partner $partner): bool
    {
        return $user->belongsToTenant($partner->tenant_id) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Partner $partner): bool
    {
        return $user->belongsToTenant($partner->tenant_id) && $this->viewAny($user);
    }

    public function delete(User $user, Partner $partner): bool
    {
        return $this->update($user, $partner);
    }
}
