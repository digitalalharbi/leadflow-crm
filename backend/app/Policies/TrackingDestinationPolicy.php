<?php

namespace App\Policies;

use App\Models\TrackingDestination;
use App\Models\User;

/** What leaves the workspace, and to whom, is a manager's decision. */
class TrackingDestinationPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isSuperAdmin() && ($user->isAdmin() || $user->isManager());
    }

    public function view(User $user, TrackingDestination $destination): bool
    {
        return $user->belongsToTenant($destination->tenant_id) && ($user->isAdmin() || $user->isManager());
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isManager();
    }

    public function update(User $user, TrackingDestination $destination): bool
    {
        return $this->view($user, $destination);
    }

    public function delete(User $user, TrackingDestination $destination): bool
    {
        return $this->view($user, $destination);
    }
}
