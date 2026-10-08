<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
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
            ->withCount('assignedLeads as leads_count')
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->input('role')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($s) => $s
                ->where('name', 'ilike', '%'.$request->input('search').'%')
                ->orWhere('email', 'ilike', '%'.$request->input('search').'%')))
            ->orderBy('name')
            ->get();

        return ApiResponse::success(['users' => UserResource::collection($users)]);
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
        ]);

        $user = User::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'password' => $data['password'],
            'role' => $data['role'],
            'phone' => $data['phone'] ?? null,
            'is_active' => true,
        ]);

        $this->audit->log('user.created', $request->user(), $user, ['role' => $data['role']]);

        return ApiResponse::created(['user' => new UserResource($user)]);
    }

    public function update(Request $request, string $uuid)
    {
        $user = User::where('tenant_id', $request->user()->tenant_id)->where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $user);

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
        ]);

        // Only an admin changes roles or activation, and never their own.
        if ((isset($data['role']) || isset($data['is_active'])) && ! $request->user()->isAdmin()) {
            throw ValidationException::withMessages(['role' => ['Only an admin can change roles.']]);
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

        $user->update($data);

        // A deactivated account loses every live session immediately.
        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            $user->tokens()->delete();
            $this->audit->log('user.deactivated', $request->user(), $user);
        }

        if (isset($data['role'])) {
            $this->audit->log('user.role_changed', $request->user(), $user, ['role' => $data['role']]);
        }

        return ApiResponse::success(['user' => new UserResource($user->fresh())]);
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
}
