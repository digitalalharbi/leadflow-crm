<?php

namespace App\Policies;

use App\Models\ReportShare;
use App\Models\User;
use App\Support\ProjectContext;

/** Who may hand out a window onto the numbers. Reading the list is for anyone who reads reports; making one is a manager's call. */
class ReportSharePolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isSuperAdmin() && ($user->isAdmin() || $user->isManager());
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, ReportShare $share): bool
    {
        return $user->belongsToTenant($share->tenant_id) && $this->viewAny($user) && $this->withinProjects($share);
    }

    public function update(User $user, ReportShare $share): bool
    {
        return $this->view($user, $share);
    }

    /**
     * A person limited to some projects reaches only the reports about those projects — every project of the report,
     * and never a report that covers them all. An unrestricted person reaches every report of the workspace.
     */
    private function withinProjects(ReportShare $share): bool
    {
        $allowed = app(ProjectContext::class)->ids();

        if ($allowed === null) {
            return true;
        }

        $covered = $share->projectIds();

        return $covered !== [] && array_diff($covered, $allowed) === [];
    }

    public function delete(User $user, ReportShare $share): bool
    {
        return $this->update($user, $share);
    }
}
