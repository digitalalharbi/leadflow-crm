<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->seesAllLeads();
    }

    public function view(User $user, User $target): bool
    {
        return $user->belongsToTenant($target->tenant_id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $target): bool
    {
        if (! $user->belongsToTenant($target->tenant_id)) {
            return false;
        }

        // Anyone can edit their own profile; only admins can edit other people.
        return $user->id === $target->id || $user->isAdmin();
    }

    /** Deactivating is the delete here — and you cannot lock yourself out. */
    public function delete(User $user, User $target): bool
    {
        return $user->belongsToTenant($target->tenant_id)
            && $user->isAdmin()
            && $user->id !== $target->id;
    }
}
