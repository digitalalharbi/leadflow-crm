<?php

namespace App\Policies;

use App\Domain\Access\Permissions;
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

    /** What a link did, to the lead and the riyal, is for those the workspace gave the tracking screens to — not for every seat. */
    public function viewAnalytics(User $user, TrackingLink $link): bool
    {
        return $user->belongsToTenant($link->tenant_id) && Permissions::has($user, 'tracking.view');
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
