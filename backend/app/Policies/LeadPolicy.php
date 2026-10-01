<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

/**
 * Tenant isolation is enforced by the global scope; these policies add the
 * role layer on top. Both checks run — belt and braces — because a policy is
 * the last thing standing if a query is ever written without the scope.
 */
class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isSuperAdmin();
    }

    public function view(User $user, Lead $lead): bool
    {
        if (! $user->belongsToTenant($lead->tenant_id)) {
            return false;
        }

        return $user->seesAllLeads() || $lead->assigned_to === $user->id;
    }

    public function create(User $user): bool
    {
        return ! $user->isSuperAdmin();
    }

    public function update(User $user, Lead $lead): bool
    {
        return $this->view($user, $lead);
    }

    /** Only managers and admins move a lead between reps. */
    public function assign(User $user, Lead $lead): bool
    {
        return $user->belongsToTenant($lead->tenant_id) && $user->seesAllLeads();
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->belongsToTenant($lead->tenant_id) && $user->isAdmin();
    }

    public function export(User $user): bool
    {
        return $user->seesAllLeads();
    }
}
