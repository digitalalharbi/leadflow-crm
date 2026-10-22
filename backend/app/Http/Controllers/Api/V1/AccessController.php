<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AccessResolver;
use App\Domain\Access\Permissions;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\AccessRole;
use App\Models\PermissionRule;
use App\Models\Project;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The Role Builder and the Permission Matrix: a workspace shapes who may do and see what.
 *
 * Roles can be built (a name, what it may do, which built-in role it behaves like) and any role —
 * built-in or built — can be changed per permission and per element of the interface. On top of
 * the roles sit three layers of rules: the workspace's defaults, a project's override, a person's
 * override. Only an admin does any of it, and an admin cannot be narrowed.
 */
class AccessController extends Controller
{
    /** Resources where more than "view" would let somebody change who may do what. */
    private const VIEW_ONLY = ['users', 'settings'];

    public function __construct(private AuditLogger $audit) {}

    public function show(Request $request)
    {
        $this->admin($request);

        $tenantId = $request->user()->tenant_id;
        $rules = PermissionRule::where('tenant_id', $tenantId)->get();
        $custom = AccessRole::where('tenant_id', $tenantId)->orderBy('name')->get();
        $counts = User::where('tenant_id', $tenantId)->selectRaw('role, access_role_id, count(*) as n')->groupBy('role', 'access_role_id')->get();

        $roleRules = fn (string $key) => $rules->where('layer', 'role')->where('role_key', $key);
        $row = function (string $key, string $name, bool $system, ?AccessRole $role, array $base, array $effective, Role $baseRole, ?string $description) use ($roleRules, $counts) {
            $mine = $roleRules($key);

            return [
                'key' => $key,
                'id' => $role?->uuid,
                'name' => $name,
                'description' => $description,
                'system' => $system,
                'base_role' => $baseRole->value,
                'scoped' => $role ? $role->project_scoped : $baseRole->isProjectScoped(),
                'external' => $role ? $role->external : $baseRole->isExternal(),
                'enforced' => $role !== null || $baseRole->usesMatrix(),
                'locked' => $key === Role::Admin->value,
                'base_grants' => Permissions::group($base),
                'grants' => Permissions::group($effective),
                'elements' => $mine->filter(fn ($r) => str_starts_with($r->permission, 'ui:'))->map(fn ($r) => ['key' => substr($r->permission, 3), 'state' => $r->effect === 'allow' ? 'show' : 'hide'])->values()->all(),
                'users_count' => (int) $counts->filter(fn ($c) => $role ? (int) $c->access_role_id === $role->id : ($c->access_role_id === null && $c->role?->value === $key))->sum('n'),
            ];
        };

        $roles = collect(Role::tenantRoles())->map(function (string $value) use ($row, $roleRules) {
            $role = Role::from($value);
            $base = Permissions::forRole($role);
            $effective = $this->withRules($base, $roleRules($value));

            return $row($value, $role->label(), true, null, $base, $effective, $role, config("permissions.descriptions.{$value}"));
        })->concat($custom->map(function (AccessRole $role) use ($row, $roleRules) {
            $base = AccessResolver::flatten($role->grants ?? []);

            return $row($role->key, $role->name, false, $role, $base, $this->withRules($base, $roleRules($role->key)), Role::from($role->base_role), $role->description);
        }))->values();

        $projects = Project::where('tenant_id', $tenantId)->get(['id', 'uuid', 'name']);
        $users = User::where('tenant_id', $tenantId)->get(['id', 'uuid', 'name']);
        $layer = fn (string $name) => $rules->where('layer', $name);

        return ApiResponse::success([
            'roles' => $roles,
            'resources' => config('permissions.resources'),
            'actions' => config('permissions.actions'),
            'view_only' => self::VIEW_ONLY,
            'kinds' => config('features.kinds'),
            'elements' => collect(config('features.elements'))->map(fn ($e, $key) => ['key' => $key, 'kind' => $e['kind'], 'needs' => $e['needs'] ?? []])->values(),
            'workspace' => $layer('workspace')->map(fn ($r) => ['permission' => $r->permission, 'effect' => $r->effect])->values(),
            'projects' => $layer('project')->groupBy('project_id')->map(fn ($set, $id) => [
                'id' => $projects->firstWhere('id', $id)?->uuid, 'name' => $projects->firstWhere('id', $id)?->name,
                'rules' => $set->map(fn ($r) => ['role_key' => $r->role_key, 'permission' => $r->permission, 'effect' => $r->effect])->values(),
            ])->filter(fn ($p) => $p['id'] !== null)->values(),
            'users' => $layer('user')->groupBy('user_id')->map(fn ($set, $id) => [
                'id' => $users->firstWhere('id', $id)?->uuid, 'name' => $users->firstWhere('id', $id)?->name,
                'rules' => $set->map(fn ($r) => ['permission' => $r->permission, 'effect' => $r->effect])->values(),
            ])->filter(fn ($u) => $u['id'] !== null)->values(),
        ]);
    }

    /** What a role holds once the workspace's role-layer rules are applied to its own grants. */
    private function withRules(array $base, $rules): array
    {
        $held = array_fill_keys($base, true);

        foreach ($rules as $rule) {
            if (str_starts_with($rule->permission, 'ui:')) {
                continue;
            }

            $rule->effect === 'allow' ? $held[$rule->permission] = true : $held[$rule->permission] = false;
        }

        return array_keys(array_filter($held));
    }

    public function storeRole(Request $request)
    {
        $this->admin($request);

        $data = $this->validateRole($request, true);
        $key = $this->uniqueKey($request->user()->tenant_id, $data['name']);

        $role = AccessRole::create([
            'key' => $key, 'name' => $data['name'], 'description' => $data['description'] ?? null,
            'base_role' => $data['base_role'], 'grants' => $this->cleanGrants($data['grants'] ?? []),
            'project_scoped' => $data['project_scoped'] ?? Role::from($data['base_role'])->isProjectScoped(),
            'external' => $data['external'] ?? Role::from($data['base_role'])->isExternal(),
            'created_by' => $request->user()->id,
        ]);

        $this->setElements($request->user(), $role->key, $data['elements'] ?? []);
        $this->audit->log('access.role_created', $request->user(), $role, ['key' => $role->key, 'grants' => $role->grants]);
        AccessResolver::flush();

        return ApiResponse::created(['role' => ['id' => $role->uuid, 'key' => $role->key]]);
    }

    /** Changes a role. A built-in role keeps its table; the workspace's differences are stored as rules. */
    public function updateRole(Request $request, string $key)
    {
        $this->admin($request);
        abort_if($key === Role::Admin->value || $key === Role::SuperAdmin->value, 422, 'The admin role cannot be changed.');

        $tenantId = $request->user()->tenant_id;
        $custom = AccessRole::where('tenant_id', $tenantId)->where('key', $key)->first();
        abort_if($custom === null && ! in_array($key, Role::tenantRoles(), true), 404);

        $data = $this->validateRole($request, false);

        DB::transaction(function () use ($request, $custom, $key, $data) {
            if ($custom) {
                $custom->fill(array_filter([
                    'name' => $data['name'] ?? null, 'description' => $data['description'] ?? null,
                    'project_scoped' => $data['project_scoped'] ?? null, 'external' => $data['external'] ?? null,
                ], fn ($v) => $v !== null));

                if (isset($data['grants'])) {
                    $custom->grants = $this->cleanGrants($data['grants']);
                }

                $custom->save();
            } elseif (isset($data['grants'])) {
                // A built-in role: store only how the workspace differs from the table.
                $base = Permissions::forRole(Role::from($key));
                $want = AccessResolver::flatten($this->cleanGrants($data['grants']));
                $rules = [];

                foreach (array_diff($want, $base) as $permission) {
                    $rules[$permission] = 'allow';
                }

                foreach (array_diff($base, $want) as $permission) {
                    $rules[$permission] = 'deny';
                }

                $this->replace($request->user(), 'role', ['role_key' => $key], $rules, false);
            }

            if (isset($data['elements'])) {
                $this->setElements($request->user(), $key, $data['elements']);
            }
        });

        $this->audit->log('access.role_updated', $request->user(), $custom, ['key' => $key, 'grants' => $data['grants'] ?? null, 'elements' => $data['elements'] ?? null]);
        AccessResolver::flush();

        return ApiResponse::success(['key' => $key]);
    }

    public function destroyRole(Request $request, string $uuid)
    {
        $this->admin($request);

        $role = AccessRole::where('tenant_id', $request->user()->tenant_id)->where('uuid', $uuid)->firstOrFail();

        if (User::where('tenant_id', $role->tenant_id)->where('access_role_id', $role->id)->exists()) {
            return ApiResponse::error('Move the people in this role to another role first.', null, 422);
        }

        PermissionRule::where('layer', 'role')->where('role_key', $role->key)->delete();
        $role->delete();
        $this->audit->log('access.role_deleted', $request->user(), null, ['key' => $role->key]);
        AccessResolver::flush();

        return ApiResponse::success(null);
    }

    /** Replaces the rules of one scope: the workspace's, a project's (optionally for one role), or a person's. */
    public function putRules(Request $request)
    {
        $this->admin($request);

        $data = $request->validate([
            'layer' => ['required', Rule::in(['workspace', 'project', 'user'])],
            'project_id' => ['required_if:layer,project', 'nullable', 'uuid'],
            'user_id' => ['required_if:layer,user', 'nullable', 'uuid'],
            'role_key' => ['nullable', 'string', 'max:48'],
            'rules' => ['present', 'array', 'max:400'],
            'rules.*.permission' => ['required', 'string', 'max:96'],
            'rules.*.effect' => ['required', Rule::in(['allow', 'deny'])],
        ]);

        $tenantId = $request->user()->tenant_id;
        $scope = [];

        if ($data['layer'] === 'project') {
            $scope['project_id'] = Project::where('tenant_id', $tenantId)->where('uuid', $data['project_id'])->firstOrFail()->id;
            $scope['role_key'] = $data['role_key'] ?? null;
        }

        if ($data['layer'] === 'user') {
            $target = User::where('tenant_id', $tenantId)->where('uuid', $data['user_id'])->firstOrFail();
            abort_if($target->isAdmin(), 422, 'An admin cannot be narrowed.');
            $scope['user_id'] = $target->id;
        }

        $rules = [];

        foreach ($data['rules'] as $rule) {
            $this->assertKnown($rule['permission'], $rule['effect']);
            $rules[$rule['permission']] = $rule['effect'];
        }

        $this->replace($request->user(), $data['layer'], $scope, $rules, true);
        $this->audit->log('access.rules_changed', $request->user(), null, ['layer' => $data['layer'], 'scope' => $scope, 'rules' => $rules]);
        AccessResolver::flush();

        return ApiResponse::success(['count' => count($rules)]);
    }

    /** What one person ends up with, and what they would see in a project — for checking a setup before trusting it. */
    public function effective(Request $request, string $uuid)
    {
        $this->admin($request);

        $user = User::where('tenant_id', $request->user()->tenant_id)->where('uuid', $uuid)->firstOrFail();
        $projectId = $request->filled('project_id') ? Project::where('tenant_id', $user->tenant_id)->where('uuid', $request->query('project_id'))->value('id') : null;

        return ApiResponse::success([
            'role' => AccessResolver::roleKey($user),
            'permissions' => Permissions::forUser($user, $projectId),
            'hidden_elements' => Permissions::hiddenElements($user, $projectId),
        ]);
    }

    private function admin(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403);
    }

    /** @return array<string,mixed> */
    private function validateRole(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$req, 'string', 'min:2', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'base_role' => [$creating ? 'required' : 'prohibited', Rule::in(array_diff(Role::tenantRoles(), [Role::Admin->value]))],
            'project_scoped' => ['sometimes', 'boolean'],
            'external' => ['sometimes', 'boolean'],
            'grants' => ['sometimes', 'array'],
            'grants.*' => ['array'],
            'grants.*.*' => ['string'],
            // A list, not a map: element keys contain dots, which validation would read as nesting.
            'elements' => ['sometimes', 'array', 'max:200'],
            'elements.*.key' => ['required', 'string', 'max:96'],
            'elements.*.state' => ['required', Rule::in(['show', 'hide'])],
        ]);
    }

    /**
     * Only known resources and actions, and no more than "view" on the areas that decide who may do
     * what — a role must not be able to hand out the keys it was given.
     *
     * @param  array<string,list<string>>  $grants
     * @return array<string,list<string>>
     */
    private function cleanGrants(array $grants): array
    {
        $resources = config('permissions.resources');
        $actions = config('permissions.actions');
        $out = [];

        foreach ($grants as $resource => $held) {
            if (! in_array($resource, $resources, true)) {
                continue;
            }

            $held = array_values(array_unique(array_filter((array) $held, fn ($a) => in_array($a, $actions, true))));
            $held = in_array($resource, self::VIEW_ONLY, true) ? array_values(array_intersect($held, ['view'])) : $held;

            if ($held !== []) {
                $out[$resource] = $held;
            }
        }

        return $out;
    }

    private function assertKnown(string $permission, string $effect): void
    {
        if (str_starts_with($permission, 'ui:')) {
            abort_unless(Permissions::element(substr($permission, 3)) !== null, 422, "Unknown element {$permission}.");

            return;
        }

        abort_unless(in_array($permission, Permissions::all(), true), 422, "Unknown permission {$permission}.");
        [$resource, $action] = explode('.', $permission, 2);
        abort_if($effect === 'allow' && in_array($resource, self::VIEW_ONLY, true) && $action !== 'view', 422, "{$permission} cannot be granted.");
    }

    /** @param  list<array{key:string,state:string}>  $elements */
    private function setElements(User $actor, string $roleKey, array $elements): void
    {
        $rules = [];

        foreach ($elements as $entry) {
            abort_unless(Permissions::element($entry['key']) !== null, 422, "Unknown element {$entry['key']}.");
            $rules["ui:{$entry['key']}"] = $entry['state'] === 'show' ? 'allow' : 'deny';
        }

        $this->replace($actor, 'role', ['role_key' => $roleKey], $rules, true);
    }

    /**
     * Rewrites the rules of one scope. `$onlyElements` leaves a role's permission rules alone (and
     * the reverse): a role's grants and its element switches are saved separately.
     *
     * @param  array<string,string>  $scope
     * @param  array<string,string>  $rules  permission → effect
     */
    private function replace(User $actor, string $layer, array $scope, array $rules, bool $elementsOnly): void
    {
        $query = PermissionRule::where('tenant_id', $actor->tenant_id)->where('layer', $layer);

        foreach (['role_key', 'project_id', 'user_id'] as $column) {
            array_key_exists($column, $scope) ? $query->where($column, $scope[$column]) : $query->whereNull($column);
        }

        // The role layer holds two kinds of rule; each save owns one of them.
        if ($layer === 'role') {
            $elementsOnly
                ? $query->where('permission', 'like', 'ui:%')
                : $query->where('permission', 'not like', 'ui:%');
        }

        $query->delete();

        foreach ($rules as $permission => $effect) {
            PermissionRule::create($scope + ['layer' => $layer, 'permission' => $permission, 'effect' => $effect, 'created_by' => $actor->id]);
        }
    }

    private function uniqueKey(int $tenantId, string $name): string
    {
        $base = Str::slug($name) ?: 'role';
        $base = in_array($base, Role::tenantRoles(), true) || $base === 'super_admin' ? "{$base}-custom" : $base;
        $key = $base;
        $n = 2;

        while (AccessRole::where('tenant_id', $tenantId)->where('key', $key)->exists()) {
            $key = $base.'-'.$n++;
        }

        return $key;
    }
}
