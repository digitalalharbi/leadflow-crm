<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * One person, several companies. Each company holds its own account for them (its own role, its own projects); these
 * routes list the ones that share this person's proven email and move the session to another. The server decides the
 * membership — a company id from the browser is only a request, checked against rows that already exist.
 */
class WorkspaceController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $rows = $this->memberships($user);

        return ApiResponse::success([
            'workspaces' => $rows->map(fn (User $row) => [
                'id' => $row->tenant->uuid,
                'name' => $row->tenant->branding?->company_name ?? $row->tenant->name,
                'role' => $row->role?->value,
                'current' => $row->id === $user->id,
            ])->values(),
        ]);
    }

    public function switch(Request $request, string $uuid)
    {
        $user = $request->user();
        $target = $this->memberships($user)->first(fn (User $row) => $row->tenant->uuid === $uuid);

        if ($target === null) {
            abort(404, 'That workspace is not available to you.');
        }

        if ($target->id === $user->id) {
            throw ValidationException::withMessages(['workspace' => ['You are already in this workspace.']]);
        }

        $this->audit->log('auth.workspace_left', $user, null, ['to' => $target->tenant->slug]);
        $this->audit->logForTenant($target->tenant_id, 'auth.workspace_entered', $target, $target, ['from_session' => true]);

        return ApiResponse::success(app(AuthController::class)->issueSession($target, $request, 'auth.workspace_switch'));
    }

    /**
     * This account, plus every other one with the same email in an open company — but only when the email is proven on
     * both sides. An account an admin created with a password nobody confirmed links to nothing.
     */
    private function memberships(User $user)
    {
        $self = User::with('tenant.branding')->whereKey($user->id)->get();

        if ($user->tenant_id === null || $user->email_verified_at === null) {
            return $self;
        }

        $others = User::with('tenant.branding')->whereNotNull('tenant_id')->where('email', $user->email)
            ->where('id', '!=', $user->id)->where('is_active', true)->whereNotNull('email_verified_at')
            ->whereHas('tenant', fn ($q) => $q->where('is_active', true))->get();

        return $self->concat($others)->sortBy(fn (User $row) => $row->tenant->name)->values();
    }
}
