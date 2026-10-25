<?php

namespace App\Policies;

use App\Domain\Access\Permissions;
use App\Models\ReportShare;
use App\Models\User;
use App\Support\ProjectContext;

/**
 * Who may hand out a window onto the numbers — decided by the permission table the workspace governs (the admin, the manager and
 * the marketing manager by default; any role the workspace builds can be given it), narrowed to the projects a person may open.
 */
class ReportSharePolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isSuperAdmin() && Permissions::has($user, 'reports_share.view');
    }

    public function create(User $user): bool
    {
        return ! $user->isSuperAdmin() && Permissions::has($user, 'reports_share.create');
    }

    public function view(User $user, ReportShare $share): bool
    {
        return $user->belongsToTenant($share->tenant_id) && $this->viewAny($user) && $this->withinProjects($share);
    }

    public function update(User $user, ReportShare $share): bool
    {
        return $user->belongsToTenant($share->tenant_id) && Permissions::has($user, 'reports_share.edit') && $this->withinProjects($share);
    }

    public function delete(User $user, ReportShare $share): bool
    {
        return $user->belongsToTenant($share->tenant_id) && Permissions::has($user, 'reports_share.disable') && $this->withinProjects($share);
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
}
