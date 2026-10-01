<?php

namespace App\Policies;

use App\Models\Import;
use App\Models\User;

/**
 * An import's failed-row report contains the raw CSV, including leads a sales
 * rep is not assigned to. Scope it to the person who uploaded it, plus anyone
 * who can already see every lead.
 */
class ImportPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isSuperAdmin();
    }

    public function view(User $user, Import $import): bool
    {
        return $user->belongsToTenant($import->tenant_id)
            && ($user->seesAllLeads() || $import->user_id === $user->id);
    }

    public function create(User $user): bool
    {
        return ! $user->isSuperAdmin();
    }

    public function process(User $user, Import $import): bool
    {
        return $this->view($user, $import);
    }
}
