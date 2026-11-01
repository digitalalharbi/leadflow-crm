<?php

namespace App\Policies;

use App\Domain\Access\AccessResolver;
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

    /** Merging two records is a manager's call: it cannot be cleanly undone. */
    public function merge(User $user, Lead $lead): bool
    {
        return $user->belongsToTenant($lead->tenant_id) && $user->seesAllLeads();
    }

    /** Only managers and admins move a lead between reps. */
    public function assign(User $user, Lead $lead): bool
    {
        return $user->belongsToTenant($lead->tenant_id) && $user->seesAllLeads();
    }

    /**
     * Naming an owner for a lead that does not exist yet. Same rule as assign(),
     * minus the tenant check there is no record to make.
     */
    public function assignOnCreate(User $user): bool
    {
        return $user->seesAllLeads();
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->belongsToTenant($lead->tenant_id) && $user->isAdmin();
    }

    public function export(User $user): bool
    {
        return $user->seesAllLeads();
    }

    /**
     * Handing a person their data, or erasing them, is the company's legal act, not a rep's: an admin, or a role
     * the workspace gave settings rights.
     */
    public function managePersonalData(User $user, Lead $lead): bool
    {
        if (! $user->belongsToTenant($lead->tenant_id)) {
            return false;
        }

        return $user->isAdmin() || ($user->usesMatrix() && AccessResolver::resolve($user, null)->has('settings.edit'));
    }
}
