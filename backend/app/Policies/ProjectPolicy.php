<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isSuperAdmin();
    }

    public function view(User $user, Project $project): bool
    {
        return $user->belongsToTenant($project->tenant_id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isManager();
    }

    public function update(User $user, Project $project): bool
    {
        return $user->belongsToTenant($project->tenant_id) && ($user->isAdmin() || $user->isManager());
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->belongsToTenant($project->tenant_id) && $user->isAdmin();
    }
}
