<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\SecondFactor;
use App\Http\Controllers\Controller;
use App\Models\TenantSetting;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A company member's own security: an authenticator-app second factor, and the list of places they are signed in.
 * Plus the workspace policy an administrator sets: every member must use one.
 */
class AccountSecurityController extends Controller
{
    public function __construct(private SecondFactor $factor, private AuditLogger $audit) {}

    public function show(Request $request)
    {
        $user = $request->user();

        return ApiResponse::success([
            'mfa_enabled' => $this->factor->enabled($user),
            'recovery_codes_left' => $this->factor->enabled($user) ? count($user->mfa_recovery_codes ?? []) : 0,
            'workspace_requires_mfa' => (bool) $user->tenant?->settings?->require_mfa,
        ]);
    }

    public function setup(Request $request)
    {
        $user = $request->user();

        if ($this->factor->enabled($user)) {
            throw ValidationException::withMessages(['code' => ['A second factor is already on. Remove it first to set up a new one.']]);
        }

        return ApiResponse::success($this->factor->begin($user, (string) config('platform.mfa_issuer')));
    }

    public function confirm(Request $request)
    {
        $user = $request->user();
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $codes = $this->factor->confirm($user, $data['code']);

        if ($codes === null) {
            throw ValidationException::withMessages(['code' => ['That code is not valid.']]);
        }

        // Sessions opened before the second factor existed end; this one stays.
        $current = $user->currentAccessToken();
        $user->tokens()->when($current instanceof PersonalAccessToken, fn ($q) => $q->whereKeyNot($current->getKey()))->delete();
        $this->audit->log('auth.mfa_enabled', $user, $user);

        return ApiResponse::success(['recovery_codes' => $codes]);
    }

    public function disable(Request $request)
    {
        $user = $request->user();
        $data = $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'string', 'max:32']]);

        if ($user->tenant?->settings?->require_mfa) {
            throw ValidationException::withMessages(['code' => ['Your workspace requires a second factor.']]);
        }

        if (! Hash::check($data['password'], $user->password) || ! $this->factor->passes($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => ['That code is not valid.']]);
        }

        $this->factor->remove($user);
        $this->audit->log('auth.mfa_disabled', $user, $user);

        return ApiResponse::success(null, 'Second factor removed.');
    }

    /** Where this person is signed in. A support session is not theirs to see or end. */
    public function sessions(Request $request)
    {
        $user = $request->user();
        $current = $user->currentAccessToken();

        $rows = $user->tokens()->where('name', 'like', 'spa-%')->orderByDesc('last_used_at')->orderByDesc('id')->get();

        return ApiResponse::success($rows->map(fn (PersonalAccessToken $t) => [
            'id' => $t->id,
            'ip' => $t->ip,
            'user_agent' => $t->user_agent,
            'created_at' => $t->created_at?->toIso8601String(),
            'last_used_at' => $t->last_used_at?->toIso8601String(),
            'current' => $current instanceof PersonalAccessToken && $current->getKey() === $t->getKey(),
        ])->values());
    }

    public function endSession(Request $request, int $id)
    {
        $user = $request->user();
        $token = $user->tokens()->where('name', 'like', 'spa-%')->whereKey($id)->first();

        abort_if($token === null, 404, 'Resource not found.');

        $token->delete();
        $this->audit->log('auth.session_ended', $user, $user, ['ip' => $token->ip]);

        return ApiResponse::success(null, 'Signed out of that device.');
    }

    /** The workspace rule: every member signs in with an authenticator app. Administrators only. */
    public function policy(Request $request)
    {
        $user = $request->user();
        abort_unless($user->usesMatrix() || $user->isAdmin(), 403, 'Your role does not include this.');

        $data = $request->validate(['require_mfa' => ['required', 'boolean']]);

        if ($data['require_mfa'] && ! $this->factor->enabled($user)) {
            throw ValidationException::withMessages(['require_mfa' => ['Turn on your own second factor first, so you are not locked out.']]);
        }

        TenantSetting::query()->where('tenant_id', $user->tenant_id)->update(['require_mfa' => $data['require_mfa']]);
        $this->audit->log('settings.mfa_policy', $user, null, ['require_mfa' => $data['require_mfa']]);

        return ApiResponse::success(['workspace_requires_mfa' => $data['require_mfa']]);
    }
}
