<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

/**
 * Everybody in the workspace may see the companies they are allowed to look at
 * (the list itself is narrowed by the company context); only an admin shapes
 * the structure. Which companies exist, who is in them and which projects they
 * own is a decision about how the holding is organised, not a sales action.
 */
class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isSuperAdmin();
    }

    public function view(User $user, Company $company): bool
    {
        return $user->belongsToTenant($company->tenant_id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Company $company): bool
    {
        return $user->belongsToTenant($company->tenant_id) && $user->isAdmin();
    }

    public function delete(User $user, Company $company): bool
    {
        return $this->update($user, $company);
    }
}
