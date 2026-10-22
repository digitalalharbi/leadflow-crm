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
    /** One entry of the element registry. (`config()` would read the dots in a key as nesting.) @return array<string,mixed>|null */
    public static function element(string $key): ?array
    {
        return config('features.elements')[$key] ?? null;
    }

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

    /** @return list<string> what this person holds once the workspace's own rules and roles are applied */
    public static function forUser(User $user, ?int $projectId = null): array
    {
        return $user->role ? AccessResolver::resolve($user, $projectId)->permissions : [];
    }

    public static function has(User $user, string $permission, ?int $projectId = null): bool
    {
        return $user->role !== null && AccessResolver::resolve($user, $projectId)->has($permission);
    }

    /** May this person see this element of the interface at all? */
    public static function sees(User $user, string $element, ?int $projectId = null): bool
    {
        return $user->role !== null && AccessResolver::resolve($user, $projectId)->sees($element);
    }

    /** @return list<string> the elements that are off for this person */
    public static function hiddenElements(User $user, ?int $projectId = null): array
    {
        return $user->role ? AccessResolver::resolve($user, $projectId)->hiddenElements : [];
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

            return [
                'role' => $value,
                'label' => $role->label(),
                'description' => config("permissions.descriptions.{$value}"),
                'scoped' => $role->isProjectScoped(),
                'external' => $role->isExternal(),
                'grants' => self::group(self::forRole($role)),
            ];
        })->values()->all();
    }

    /**
     * @param  list<string>  $permissions
     * @return array<string,list<string>>
     */
    public static function group(array $permissions): array
    {
        $grants = [];

        foreach ($permissions as $permission) {
            [$resource, $action] = explode('.', $permission, 2);
            $grants[$resource][] = $action;
        }

        return $grants;
    }

    /**
     * What a response may not contain for this person, by kind: `contacts`, `spend`, `revenue`.
     * Only the specialised roles are scrubbed — the long-standing roles see what they always saw.
     *
     * @return list<string>
     */
    public static function hiddenData(User $user): array
    {
        if ($user->role === null) {
            return [];
        }

        // The specialised roles hold only what the table gives them. A long-standing role is held
        // back only from what the workspace has explicitly taken away.
        if ($user->usesMatrix()) {
            return array_values(array_filter(['contacts', 'spend', 'revenue'], fn (string $kind) => ! self::has($user, "{$kind}.view")));
        }

        $denied = AccessResolver::resolve($user)->denied;

        return array_values(array_filter(['contacts', 'spend', 'revenue'], fn (string $kind) => in_array("{$kind}.view", $denied, true)));
    }
}
