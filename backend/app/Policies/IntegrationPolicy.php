<?php

namespace App\Policies;

use App\Models\Integration;
use App\Models\User;

/** Integrations hold credentials, so they are admin-only end to end. */
class IntegrationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Integration $integration): bool
    {
        return $user->belongsToTenant($integration->tenant_id) && $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Integration $integration): bool
    {
        return $this->view($user, $integration);
    }

    public function delete(User $user, Integration $integration): bool
    {
        return $this->view($user, $integration);
    }
}
