<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Permissions;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Partner;
use App\Models\Project;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Leads\PhoneLanguage;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(['projects:projects.id,projects.uuid', 'partner:partners.id,partners.uuid,partners.name'])
            ->withCount('assignedLeads as leads_count')
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->input('role')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($s) => $s
                ->where('name', 'ilike', '%'.$request->input('search').'%')
                ->orWhere('email', 'ilike', '%'.$request->input('search').'%')))
            ->orderBy('name')
            ->get();

        return ApiResponse::success([
            'users' => UserResource::collection($users),
            /*
             * The languages worth claiming, served rather than hardcoded in the
             * interface: a rep can only be matched on a language the system can
             * read off a number, so the screen offers exactly that list and
             * grows with it instead of drifting behind it.
             */
            'languages' => PhoneLanguage::known(),
        ]);
    }

    /** Lightweight list for assignment dropdowns — available to every role. */
    public function assignable(Request $request)
    {
        $users = User::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('is_active', true)
            ->whereIn('role', [Role::Admin->value, Role::Manager->value, Role::Sales->value])
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'role']);

        return ApiResponse::success([
            'users' => $users->map(fn ($u) => [
                'id' => $u->uuid,
                'name' => $u->name,
                'role' => $u->role?->value,
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', User::class);

        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(Role::tenantRoles())],
            'phone' => ['nullable', 'string', 'max:32'],
            'project_ids' => ['sometimes', 'array', 'max:200'],
            'project_ids.*' => ['uuid'],
            'partner_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $user = User::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'password' => $data['password'],
            'role' => $data['role'],
            'phone' => $data['phone'] ?? null,
            'partner_id' => $this->partnerId($data),
            'is_active' => true,
        ]);

        $this->giveProjects($request, $user, $data['project_ids'] ?? null);

        $this->audit->log('user.created', $request->user(), $user, ['role' => $data['role']]);

        return ApiResponse::created(['user' => new UserResource($user->load('projects:projects.id,projects.uuid'))]);
    }

    public function update(Request $request, string $uuid)
    {
        $user = User::where('tenant_id', $request->user()->tenant_id)->where('uuid', $uuid)->firstOrFail();

        /*
         * Two authorities, not one. Changing somebody's account takes an admin;
         * saying how their team's leads are shared out takes a manager, which
         * is a different and smaller thing — so a request that only touches the
         * second is judged by the second.
         */
        $routing = ['covers_cities', 'assignment_weight', 'speaks', 'handles'];
        $touched = array_keys($request->all());

        $this->authorize(
            $touched !== [] && array_diff($touched, $routing) === [] ? 'routeWork' : 'update',
            $user,
        );

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes', 'email', 'max:255',
                Rule::unique('users', 'email')
                    ->where('tenant_id', $user->tenant_id)
                    ->whereNull('deleted_at')
                    ->ignore($user->id),
            ],
            'phone' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', 'in:ar,en'],
            'role' => ['sometimes', Rule::in(Role::tenantRoles())],
            'is_active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'string', 'min:8'],
            // The projects a project-scoped role (project manager, client, broker) is limited to.
            'project_ids' => ['sometimes', 'array', 'max:200'],
            'project_ids.*' => ['uuid'],
            // The partner a broker login belongs to: their leads and links, and nobody else's.
            'partner_id' => ['sometimes', 'nullable', 'uuid'],

            /*
             * Away until, rather than away.
             *
             * Deliberately not gated behind an admin the way `role` and
             * `is_active` are: being on leave is a fact about the person's week,
             * not a change to their account, and a rep who has to ask an admin
             * to mark it is a rep whose leads keep arriving anyway. A manager
             * can already change their team; this is less than that.
             */
            'away_until' => ['sometimes', 'nullable', 'date', 'before:+1 year'],

            /*
             * Where this rep sells, and how much of the load they carry.
             *
             * Both are workload policy rather than facts about the person, so
             * unlike `away_until` they take an admin or a manager — the guard
             * below. Empty coverage means every city and a weight of one means
             * an equal share, which is where every workspace starts.
             */
            'covers_cities' => ['sometimes', 'nullable', 'array', 'max:50'],
            'covers_cities.*' => ['string', 'max:120'],
            'assignment_weight' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'handles' => ['sometimes', 'nullable', 'array', 'max:5'],
            'handles.*' => ['string', 'in:buy,rent'],

            /*
             * Which languages this rep can sell in. Empty means every language,
             * same as coverage — and for the same reason, it is the workspace's
             * call rather than the rep's own: a rep who could edit their own
             * list could quietly opt out of the half of the pipeline they do
             * not want.
             *
             * Checked for shape rather than against a list of known codes. The
             * language the system infers from a number comes from a table that
             * will grow, and a validator built from today's copy of it would
             * start rejecting a claim that is true about a person.
             */
            'speaks' => ['sometimes', 'nullable', 'array', 'max:20'],
            'speaks.*' => ['string', 'regex:/^[A-Za-z]{2,3}([-_][A-Za-z]{2,4})?$/'],
        ]);

        // Only an admin changes roles, activation or project scope, and never their own role.
        if ((isset($data['role']) || isset($data['is_active']) || array_key_exists('project_ids', $data) || array_key_exists('partner_id', $data)) && ! $request->user()->isAdmin()) {
            throw ValidationException::withMessages(['role' => ['Only an admin can change roles.']]);
        }

        /*
         * Coverage, weight and spoken languages decide who gets which
         * customer, so they are the workspace's call rather than the rep's own
         * — a rep who could widen their own weight could quietly take the whole
         * pipeline, and one who could narrow their own languages could quietly
         * leave it.
         */
        if (array_intersect($routing, array_keys($data)) !== []
            && $request->user()->cannot('routeWork', $user)) {
            throw ValidationException::withMessages([
                'assignment_weight' => ['Only an admin or a manager can change how leads are shared out.'],
            ]);
        }

        if ($user->id === $request->user()->id && array_key_exists('is_active', $data) && ! $data['is_active']) {
            throw ValidationException::withMessages(['is_active' => ['You cannot deactivate your own account.']]);
        }

        if ($user->id === $request->user()->id && isset($data['role']) && $data['role'] !== $user->role->value) {
            throw ValidationException::withMessages(['role' => ['You cannot change your own role.']]);
        }

        if (isset($data['email'])) {
            $data['email'] = strtolower($data['email']);
        }

        $projectIds = $data['project_ids'] ?? null;
        unset($data['project_ids']);

        if (array_key_exists('partner_id', $data)) {
            $before = $user->partner_id;
            $data['partner_id'] = $this->partnerId($data);

            if ($before !== $data['partner_id']) {
                $this->audit->log('user.partner_changed', $request->user(), $user, ['before' => $before, 'after' => $data['partner_id']]);
            }
        }

        $user->update($data);

        if ($projectIds !== null) {
            $this->giveProjects($request, $user, $projectIds);
        }

        // A deactivated account loses every live session immediately.
        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            $user->tokens()->delete();
            $this->audit->log('user.deactivated', $request->user(), $user);
        }

        if (isset($data['role'])) {
            $this->audit->log('user.role_changed', $request->user(), $user, ['role' => $data['role']]);
        }

        return ApiResponse::success(['user' => new UserResource($user->fresh()->load(['projects:projects.id,projects.uuid', 'partner:partners.id,partners.uuid,partners.name']))]);
    }

    public function destroy(Request $request, string $uuid)
    {
        $user = User::where('tenant_id', $request->user()->tenant_id)->where('uuid', $uuid)->firstOrFail();
        $this->authorize('delete', $user);

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $user->update(['is_active' => false]);
            $user->delete();
        });

        $this->audit->log('user.deleted', $request->user(), $user);

        return ApiResponse::success(null, 'User removed.');
    }

    /** A partner's uuid → id, inside this workspace only. */
    private function partnerId(array $data): ?int
    {
        return filled($data['partner_id'] ?? null) ? Partner::where('uuid', $data['partner_id'])->value('id') : null;
    }

    /** The projects somebody is given — only this workspace's, and every change on the record. */
    private function giveProjects(Request $request, User $user, ?array $uuids): void
    {
        if ($uuids === null) {
            return;
        }

        $before = $user->projects()->pluck('projects.uuid')->all();
        $ids = Project::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->whereIn('uuid', $uuids)->pluck('id')->all();

        $user->projects()->sync($ids);

        $after = $user->projects()->pluck('projects.uuid')->all();

        if ($before !== $after) {
            $this->audit->log('user.projects_changed', $request->user(), $user, ['before' => $before, 'after' => $after]);
        }
    }

    /** Every role, and what each may do: the matrix the interface and the API both read. */
    public function roles(Request $request)
    {
        $this->authorize('viewAny', User::class);

        return ApiResponse::success(['roles' => Permissions::matrix(), 'resources' => config('permissions.resources'), 'actions' => config('permissions.actions')]);
    }
}
