<?php

namespace App\Domain\Access;

use App\Enums\Role;
use App\Models\User;

/** Where somebody lands after signing in. The server decides, from what they may actually open. */
class Destination
{
    public static function for(User $user): string
    {
        if ($user->isSuperAdmin()) {
            return '/platform';
        }

        $permissions = Permissions::forUser($user);
        $hidden = Permissions::hiddenElements($user);
        $open = fn (string $permission, ?string $page) => in_array($permission, $permissions, true) && ($page === null || ! in_array($page, $hidden, true));

        return match (true) {
            $user->role === Role::Sales && $open('leads.execute', null) => '/work',
            $open('dashboard.view', 'page.dashboard') => '/dashboard',
            $open('leads.execute', null) => '/work',
            $open('leads.view', 'page.leads') => '/leads',
            $open('visits.view', 'page.visits') => '/visits',
            $open('analytics.view', 'page.reports') => '/reports',
            default => '/settings/profile',
        };
    }
}
