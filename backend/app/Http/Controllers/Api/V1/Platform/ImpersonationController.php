<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Platform\PlatformAudit;
use App\Http\Controllers\Controller;
use App\Models\Impersonation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Support access, explicit at both ends. Starting needs a reason and names who is entered; it issues a short-lived
 * session for that person that cannot change passwords, sessions or secrets; it is written to the platform trail AND to
 * the company's own trail, so the company sees that support was inside. Stopping (or expiry) ends the session.
 * No credential of the company is ever shown — the owner never learns a password.
 */
class ImpersonationController extends Controller
{
    public const TOKEN_NAME = 'impersonation';

    public function __construct(private PlatformAudit $audit) {}

    public function start(Request $request, string $uuid)
    {
        $tenant = Tenant::where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'user_id' => ['nullable', 'uuid'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $target = User::query()->where('tenant_id', $tenant->id)->where('is_active', true)
            ->when($data['user_id'] ?? null, fn ($q, $id) => $q->where('uuid', $id),
                fn ($q) => $q->where('id', $tenant->owner_user_id ?? 0))
            ->first()
            ?? ($data['user_id'] ?? null ? null : User::query()->where('tenant_id', $tenant->id)->where('is_active', true)->where('role', 'admin')->orderBy('id')->first());

        abort_if($target === null, 404, 'No active person to enter as.');

        $expires = now()->addMinutes(config('platform.impersonation_minutes'));
        $token = $target->createToken(self::TOKEN_NAME, ['impersonation'], $expires);

        $session = Impersonation::create([
            'platform_user_id' => $request->user()->id,
            'tenant_id' => $tenant->id,
            'target_user_id' => $target->id,
            'token_id' => $token->accessToken->id,
            'reason' => $data['reason'],
            'ip' => $request->ip(),
            'started_at' => now(),
            'expires_at' => $expires,
        ]);

        $this->audit->log('impersonation.started', $request->user(), $tenant, $target, ['reason' => $data['reason'], 'as' => $target->email, 'expires_at' => $expires->toIso8601String()]);
        $this->companyTrail($tenant, 'support.session_started', $target, ['reason' => $data['reason'], 'expires_at' => $expires->toIso8601String()]);

        return ApiResponse::created([
            'token' => $token->plainTextToken,
            'session' => $this->present($session->load('target', 'tenant')),
        ]);
    }

    /** Called with the support session's own token, from inside the company. */
    public function stop(Request $request)
    {
        $token = $request->user()->currentAccessToken();

        abort_unless($token instanceof PersonalAccessToken && $token->name === self::TOKEN_NAME, 404);

        $session = Impersonation::where('token_id', $token->id)->whereNull('ended_at')->first();
        $token->delete();

        if ($session) {
            $session->forceFill(['ended_at' => now(), 'ended_by' => 'platform'])->save();
            $tenant = Tenant::find($session->tenant_id);
            $this->audit->log('impersonation.ended', User::find($session->platform_user_id), $tenant, $request->user(), ['minutes' => $session->started_at->diffInMinutes(now())]);
            $this->companyTrail($tenant, 'support.session_ended', $request->user(), []);
        }

        return ApiResponse::success(null, 'Support session ended.');
    }

    /** The console's list: who entered which company, when, why, and whether it is still open. */
    public function index(Request $request)
    {
        $rows = Impersonation::with(['platformUser:id,name,email', 'target:id,name,email', 'tenant:id,uuid,name'])
            ->latest('id')->limit(100)->get();

        return ApiResponse::success(['sessions' => $rows->map(fn ($s) => $this->present($s))]);
    }

    public function revoke(Request $request, string $uuid)
    {
        $session = Impersonation::where('uuid', $uuid)->firstOrFail();

        PersonalAccessToken::where('id', $session->token_id)->delete();

        if ($session->ended_at === null) {
            $session->forceFill(['ended_at' => now(), 'ended_by' => 'revoked'])->save();
            $tenant = Tenant::find($session->tenant_id);
            $this->audit->log('impersonation.revoked', $request->user(), $tenant, $session);
            $this->companyTrail($tenant, 'support.session_ended', User::find($session->target_user_id), ['revoked' => true]);
        }

        return ApiResponse::success(['session' => $this->present($session->fresh(['target', 'tenant', 'platformUser']))]);
    }

    /** The company's own trail says support was here, as whom and why. */
    private function companyTrail(?Tenant $tenant, string $action, ?User $as, array $changes): void
    {
        if ($tenant !== null) {
            app(AuditLogger::class)->logForTenant($tenant->id, $action, $as, $as, $changes);
        }
    }

    private function present(Impersonation $session): array
    {
        return [
            'id' => $session->uuid,
            'tenant' => $session->tenant ? ['id' => $session->tenant->uuid, 'name' => $session->tenant->name] : null,
            'as' => $session->target ? ['name' => $session->target->name, 'email' => $session->target->email] : null,
            'by' => $session->platformUser ? ['name' => $session->platformUser->name] : null,
            'reason' => $session->reason,
            'started_at' => $session->started_at->toIso8601String(),
            'expires_at' => $session->expires_at->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
            'ended_by' => $session->ended_by,
            'live' => $session->isLive(),
        ];
    }
}
