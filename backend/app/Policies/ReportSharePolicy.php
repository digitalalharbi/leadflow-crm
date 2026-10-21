<?php

namespace App\Policies;

use App\Models\ReportShare;
use App\Models\User;

/** Who may hand out a window onto the numbers. Reading the list is for anyone who reads reports; making one is a manager's call. */
class ReportSharePolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isSuperAdmin() && ($user->isAdmin() || $user->isManager());
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ReportShare $share): bool
    {
        return $user->belongsToTenant($share->tenant_id) && $this->viewAny($user);
    }

    public function delete(User $user, ReportShare $share): bool
    {
        return $this->update($user, $share);
    }
}
