<?php

namespace App\Policies;

use App\Models\TrackingLink;
use App\Models\User;

class TrackingLinkPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isSuperAdmin();
    }

    public function view(User $user, TrackingLink $link): bool
    {
        return $user->belongsToTenant($link->tenant_id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isManager();
    }

    public function update(User $user, TrackingLink $link): bool
    {
        return $user->belongsToTenant($link->tenant_id) && ($user->isAdmin() || $user->isManager());
    }

    public function delete(User $user, TrackingLink $link): bool
    {
        return $this->update($user, $link);
    }
}
