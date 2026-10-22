<?php

namespace App\Policies;

use App\Models\AutomationRule;
use App\Models\User;

/**
 * Who may write the rules that then act on everybody's leads.
 *
 * Writing is restricted to admins and managers, the two roles that already hold
 * workspace-wide authority over leads. It deliberately is *not* tied to "can
 * create a lead": every sales rep can do that, and a rep who could write rules
 * could write one that quietly reassigned the whole workspace's incoming leads
 * to themselves — arriving as ordinary automation, with no reassignment anybody
 * would think to question.
 *
 * Reading stays open to the whole workspace, including reps. A rep looking at
 * their own lead should be able to see which rule moved it and why; hiding the
 * trace from the person holding the phone is how automation becomes the thing
 * the team works around.
 */
class AutomationRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tenant_id !== null;
    }

    public function view(User $user, AutomationRule $rule): bool
    {
        return $user->belongsToTenant($rule->tenant_id);
    }

    public function create(User $user): bool
    {
        return $user->tenant_id !== null && ($user->isAdmin() || $user->isManager());
    }

    public function update(User $user, AutomationRule $rule): bool
    {
        return $this->view($user, $rule) && ($user->isAdmin() || $user->isManager());
    }

    public function delete(User $user, AutomationRule $rule): bool
    {
        return $this->update($user, $rule);
    }
}
