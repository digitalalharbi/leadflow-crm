<?php

namespace App\Policies;

use App\Models\LandingForm;
use App\Models\User;

/** A form is a promise about what is asked of a customer: a manager's call, like the link that opens it. */
class LandingFormPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isSuperAdmin();
    }

    public function view(User $user, LandingForm $form): bool
    {
        return $user->belongsToTenant($form->tenant_id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isManager();
    }

    public function update(User $user, LandingForm $form): bool
    {
        return $user->belongsToTenant($form->tenant_id) && ($user->isAdmin() || $user->isManager());
    }

    public function delete(User $user, LandingForm $form): bool
    {
        return $this->update($user, $form);
    }
}
