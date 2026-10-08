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

    /**
     * May decide how this person's share of the leads is worked out — the
     * cities they cover, the languages they sell in, the weight they carry.
     *
     * A separate authority from `update` on purpose. Editing somebody's account
     * is an admin's job, but deciding who on the team takes which customer is a
     * manager's, and a manager who has to open a ticket to record that a new
     * rep speaks English is a manager who will not record it.
     */
    public function routeWork(User $user, User $target): bool
    {
        if (! $user->belongsToTenant($target->tenant_id)) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        /*
         * A manager sets their team's, never their own. Managers take leads
         * like everybody else here, and the one thing a weight must not be is
         * something you can award yourself.
         */
        return $user->isManager() && $user->id !== $target->id;
    }

    /** Deactivating is the delete here — and you cannot lock yourself out. */
    public function delete(User $user, User $target): bool
    {
        return $user->belongsToTenant($target->tenant_id)
            && $user->isAdmin()
            && $user->id !== $target->id;
    }
}
