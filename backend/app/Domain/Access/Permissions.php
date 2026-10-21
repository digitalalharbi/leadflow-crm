<?php

namespace App\Domain\Access;

use App\Enums\Role;
use App\Models\User;

/**
 * What a person may do, in one place.
 *
 * Reads config/permissions.php and answers "may this user do `campaigns.edit`?". The interface is
 * given the same list (`/auth/me`), so what it hides and what the API refuses are one table.
 */
class Permissions
{
    /** @return list<string> every "resource.action" a role holds */
    public static function forRole(Role $role): array
    {
        if ($role === Role::SuperAdmin) {
            return self::all();
        }

        $grant = config("permissions.roles.{$role->value}", []);

        if ($grant === '*') {
            return self::all();
        }

        $out = [];

        foreach ($grant as $resource => $actions) {
            foreach ($actions as $action) {
                $out[] = "{$resource}.{$action}";
            }
        }

        return $out;
    }

    /** @return list<string> */
    public static function forUser(User $user): array
    {
        return $user->role ? self::forRole($user->role) : [];
    }

    public static function has(User $user, string $permission): bool
    {
        return in_array($permission, self::forUser($user), true);
    }

    /** @return list<string> */
    public static function all(): array
    {
        $out = [];

        foreach (config('permissions.resources') as $resource) {
            foreach (config('permissions.actions') as $action) {
                $out[] = "{$resource}.{$action}";
            }
        }

        return $out;
    }

    /**
     * The whole matrix, for the roles screen: each role with what it holds per resource.
     *
     * @return list<array{role:string,label:string,description:?string,scoped:bool,external:bool,grants:array<string,list<string>>}>
     */
    public static function matrix(): array
    {
        return collect(Role::tenantRoles())->map(function (string $value) {
            $role = Role::from($value);
            $grants = [];

            foreach (self::forRole($role) as $permission) {
                [$resource, $action] = explode('.', $permission, 2);
                $grants[$resource][] = $action;
            }

            return [
                'role' => $value,
                'label' => $role->label(),
                'description' => config("permissions.descriptions.{$value}"),
                'scoped' => $role->isProjectScoped(),
                'external' => $role->isExternal(),
                'grants' => $grants,
            ];
        })->values()->all();
    }

    /**
     * What a response may not contain for this person, by kind: `contacts`, `spend`, `revenue`.
     * Only the specialised roles are scrubbed — the long-standing roles see what they always saw.
     *
     * @return list<string>
     */
    public static function hiddenData(User $user): array
    {
        if (! $user->role?->usesMatrix()) {
            return [];
        }

        return array_values(array_filter(['contacts', 'spend', 'revenue'], fn (string $kind) => ! self::has($user, "{$kind}.view")));
    }
}
