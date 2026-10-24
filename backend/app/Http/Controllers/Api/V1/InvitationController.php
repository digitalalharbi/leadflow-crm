<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\PlanLimits;
use App\Domain\Mail\AccountEmail;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\AccessRole;
use App\Models\Invitation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A company admin brings someone onto the team by inviting them: an email with a link, a role, the projects they may see.
 * Nobody types a password for anyone else. A pending invitation holds its seat until it is accepted, revoked or expires.
 */
class InvitationController extends Controller
{
    public function __construct(private AuditLogger $audit, private AccountEmail $email, private TenantContext $context) {}

    public function index(Request $request)
    {
        $this->authorize('create', User::class);

        $rows = Invitation::whereNull('accepted_at')->whereNull('revoked_at')->latest('id')->get();

        return ApiResponse::success(['invitations' => $rows->map(fn (Invitation $i) => $this->present($i))]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', User::class);
        $tenant = $this->context->get();

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required_without:access_role_id', Rule::in(Role::tenantRoles())],
            'access_role_id' => ['sometimes', 'nullable', 'uuid'],
            'phone' => ['nullable', 'string', 'max:32'],
            'project_ids' => ['sometimes', 'array', 'max:200'],
            'project_ids.*' => ['uuid'],
            'all_projects' => ['sometimes', 'boolean'],
        ]);

        $email = strtolower($data['email']);

        if (User::where('tenant_id', $tenant->id)->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => ['This person is already on the team.']]);
        }

        if (Invitation::where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists()) {
            throw ValidationException::withMessages(['email' => ['An invitation to this address is already waiting. Resend it instead.']]);
        }

        if (! empty($data['access_role_id'])) {
            $custom = AccessRole::where('uuid', $data['access_role_id'])->firstOrFail();
            $data['role'] = $custom->base_role ?? $data['role'] ?? Role::Sales->value;
            $data['access_role_id'] = $custom->id;
        }

        PlanLimits::ensureSeatFor($tenant, $data['role']);

        $invitation = new Invitation([
            'email' => $email,
            'name' => $data['name'] ?? null,
            'role' => $data['role'],
            'access_role_id' => $data['access_role_id'] ?? null,
            'phone' => $data['phone'] ?? null,
            'project_ids' => $data['project_ids'] ?? [],
            // A rep works the projects they are given unless told otherwise; a manager-type role sees all unless limited.
            'all_projects' => $data['all_projects'] ?? ($data['role'] !== 'sales'),
            'invited_by' => $request->user()->id,
        ]);
        $token = $invitation->issueToken();
        $invitation->forceFill(['last_sent_at' => now(), 'send_count' => 1])->save();

        $this->email->invitation($invitation, $token, $tenant, $request->user()->name);
        $this->audit->log('invitation.sent', $request->user(), $invitation, ['email' => $email, 'role' => $invitation->role]);

        return ApiResponse::created(['invitation' => $this->present($invitation)]);
    }

    /** A new link, a new week. The old link stops working. */
    public function resend(Request $request, string $uuid)
    {
        $this->authorize('create', User::class);
        $invitation = Invitation::where('uuid', $uuid)->whereNull('accepted_at')->whereNull('revoked_at')->firstOrFail();

        $token = $invitation->issueToken();
        $invitation->forceFill(['last_sent_at' => now(), 'send_count' => $invitation->send_count + 1])->save();

        $this->email->invitation($invitation, $token, $this->context->get(), $request->user()->name);
        $this->audit->log('invitation.resent', $request->user(), $invitation, ['email' => $invitation->email]);

        return ApiResponse::success(['invitation' => $this->present($invitation)]);
    }

    public function destroy(Request $request, string $uuid)
    {
        $this->authorize('create', User::class);
        $invitation = Invitation::where('uuid', $uuid)->whereNull('accepted_at')->whereNull('revoked_at')->firstOrFail();
        $invitation->forceFill(['revoked_at' => now()])->save();

        $this->audit->log('invitation.revoked', $request->user(), $invitation, ['email' => $invitation->email]);

        return ApiResponse::success(null, 'Invitation revoked.');
    }

    private function present(Invitation $invitation): array
    {
        return [
            'id' => $invitation->uuid,
            'email' => $invitation->email,
            'name' => $invitation->name,
            'role' => $invitation->role,
            'all_projects' => $invitation->all_projects,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'last_sent_at' => $invitation->last_sent_at?->toIso8601String(),
            'expired' => ! $invitation->expires_at->isFuture(),
        ];
    }
}
