<?php

namespace App\Domain\Access;

use App\Enums\Role;
use App\Models\AccessRole;
use App\Models\PermissionRule;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Works out what a person may do and see, from four layers, each overriding the one before:
 *
 *   workspace default → role → project override → user override
 *
 * The role layer is the role's own grants (the built-in table, or the role the workspace built)
 * plus the rules the workspace has set on that role. A rule is allow or deny for a permission
 * (`campaigns.edit`) or an element of the interface (`ui:dashboard.kpi.revenue`). Nothing said
 * anywhere means no for a permission, and "as the data allows" for an element. An admin is never
 * narrowed: a workspace cannot lock itself out.
 */
class AccessResolver
{
    /** @var array<int,Collection<int,PermissionRule>> rules per tenant, for the length of the process/request */
    private static array $rules = [];

    /** @var array<string,Access> */
    private static array $resolved = [];

    /** @var array<int,AccessRole|null> */
    private static array $roles = [];

    /** Forget what was read — after a rule or a role changed, and between tests. */
    public static function flush(): void
    {
        self::$rules = [];
        self::$resolved = [];
        self::$roles = [];
    }

    public static function customRole(User $user): ?AccessRole
    {
        if ($user->access_role_id === null) {
            return null;
        }

        return self::$roles[$user->access_role_id] ??= AccessRole::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->find($user->access_role_id);
    }

    /** The key a user's role goes by in rules: the built-in role's value, or the custom role's key. */
    public static function roleKey(User $user): string
    {
        return self::customRole($user)?->key ?? ($user->role?->value ?? '');
    }

    /** @return Collection<int,PermissionRule> */
    private static function rulesFor(int $tenantId): Collection
    {
        return self::$rules[$tenantId] ??= PermissionRule::withoutGlobalScopes()->where('tenant_id', $tenantId)->get();
    }

    public static function resolve(User $user, ?int $projectId = null): Access
    {
        $cacheKey = $user->id.'|'.($user->access_role_id ?? '').'|'.($user->role?->value ?? '').'|'.($projectId ?? '');

        return self::$resolved[$cacheKey] ??= self::compute($user, $projectId);
    }

    private static function compute(User $user, ?int $projectId): Access
    {
        if ($user->role === Role::SuperAdmin || $user->role === Role::Admin) {
            return new Access(Permissions::all(), [], []);
        }

        $rules = $user->tenant_id ? self::rulesFor($user->tenant_id) : collect();
        $roleKey = self::roleKey($user);

        /** @var array<string,bool> $state permission or ui:element → allowed */
        $state = [];
        $apply = function (iterable $statements) use (&$state) {
            foreach ($statements as $permission => $allowed) {
                $state[$permission] = $allowed;
            }
        };
        $fromRules = fn (Collection $set) => $set->mapWithKeys(fn (PermissionRule $r) => [$r->permission => $r->effect === 'allow']);

        // 1. The workspace's defaults, for everybody.
        $apply($fromRules($rules->where('layer', 'workspace')));

        // 2. The role: what it holds, then what the workspace has changed about it.
        $apply(array_fill_keys(self::roleGrants($user), true));
        $apply($fromRules($rules->where('layer', 'role')->where('role_key', $roleKey)));

        // 3. The project, when the question is about one.
        if ($projectId !== null) {
            $apply($fromRules($rules->where('layer', 'project')->where('project_id', $projectId)->filter(fn (PermissionRule $r) => $r->role_key === null || $r->role_key === $roleKey)));
        }

        // 4. The person.
        $apply($fromRules($rules->where('layer', 'user')->where('user_id', $user->id)));

        $permissions = [];
        $denied = [];
        $elements = [];

        foreach ($state as $permission => $allowed) {
            if (str_starts_with($permission, 'ui:')) {
                $elements[substr($permission, 3)] = $allowed;
            } elseif ($allowed) {
                $permissions[] = $permission;
            } else {
                $denied[] = $permission;
            }
        }

        // Only permissions that exist; a stale rule must not conjure one.
        $known = array_flip(Permissions::all());
        $permissions = array_values(array_filter($permissions, fn (string $p) => isset($known[$p])));

        $hidden = [];

        foreach (config('features.elements') as $key => $element) {
            $held = collect($element['needs'] ?? [])->every(fn (string $p) => in_array($p, $permissions, true));

            if (! $held || ($elements[$key] ?? true) === false) {
                $hidden[] = $key;
            }
        }

        return new Access($permissions, $denied, $hidden);
    }

    /** @return list<string> what the role itself holds, before any workspace rule */
    public static function roleGrants(User $user): array
    {
        $custom = self::customRole($user);

        if ($custom !== null) {
            return self::flatten($custom->grants ?? []);
        }

        return $user->role ? Permissions::forRole($user->role) : [];
    }

    /**
     * @param  array<string,list<string>>  $grants
     * @return list<string>
     */
    public static function flatten(array $grants): array
    {
        $out = [];

        foreach ($grants as $resource => $actions) {
            foreach ($actions as $action) {
                $out[] = "{$resource}.{$action}";
            }
        }

        return $out;
    }
}
