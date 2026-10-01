<?php

namespace App\Policies;

use App\Models\FollowUp;
use App\Models\User;

class FollowUpPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isSuperAdmin();
    }

    public function view(User $user, FollowUp $followUp): bool
    {
        return $user->belongsToTenant($followUp->tenant_id)
            && ($user->seesAllLeads() || $followUp->assigned_to === $user->id);
    }

    public function create(User $user): bool
    {
        return ! $user->isSuperAdmin();
    }

    public function update(User $user, FollowUp $followUp): bool
    {
        return $this->view($user, $followUp);
    }

    public function delete(User $user, FollowUp $followUp): bool
    {
        return $this->view($user, $followUp);
    }
}
