<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Auth\SecondFactor;
use App\Domain\Platform\PlatformAudit;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The platform owner's own door. Separate from a company's login in every way that matters: its own endpoint, only
 * platform accounts, a token that carries the `platform` ability (the console answers nothing else), and a second
 * factor (TOTP) that can be required for every owner account by configuration.
 */
class PlatformAuthController extends Controller
{
    public function __construct(private PlatformAudit $audit) {}

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:32'],
        ]);

        $user = User::query()->whereNull('tenant_id')->where('email', strtolower($data['email']))->first();

        if (! $user || ! $user->isSuperAdmin() || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            if (! $user) {
                Hash::make($data['password']); // same cost whether or not the account exists
            }

            $this->audit->log('platform.login_failed', null, null, null, ['email' => strtolower($data['email']), 'ip' => $request->ip()]);

            throw ValidationException::withMessages(['email' => ['These credentials do not match our records.']]);
        }

        if ($user->mfa_enabled_at !== null) {
            if (blank($data['code'] ?? null)) {
                return ApiResponse::success(['mfa_required' => true]);
            }

            if (! $this->passesSecondFactor($user, (string) $data['code'])) {
                $this->audit->log('platform.mfa_failed', $user);

                throw ValidationException::withMessages(['code' => ['That code is not valid.']]);
            }
        }

        // Without a second factor while one is required: a session that can only enrol one.
        $enrolOnly = config('platform.require_mfa') && $user->mfa_enabled_at === null;
        $token = $user->createToken('platform', [$enrolOnly ? 'platform-enrol' : 'platform'], now()->addHours(config('platform.session_hours')));

        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        $this->audit->log('platform.login', $user, null, null, ['ip' => $request->ip(), 'mfa' => $user->mfa_enabled_at !== null]);

        return ApiResponse::success([
            'token' => $token->plainTextToken,
            'is_super_admin' => true,
            'mfa_enabled' => $user->mfa_enabled_at !== null,
            'mfa_enrolment_required' => $enrolOnly,
            'user' => ['id' => $user->uuid, 'name' => $user->name, 'email' => $user->email],
        ]);
    }

    /** Starts enrolment: a fresh secret the authenticator app scans. Not active until confirmed with a code. */
    public function setupMfa(Request $request)
    {
        $user = $request->user();
        $secret = Totp::generateSecret();
        $user->forceFill(['mfa_secret' => $secret])->save();

        return ApiResponse::success([
            'secret' => $secret,
            'otpauth_uri' => Totp::uri($secret, $user->email, config('platform.mfa_issuer')),
        ]);
    }

    public function confirmMfa(Request $request)
    {
        $user = $request->user();
        $data = $request->validate(['code' => ['required', 'string']]);

        if ($user->mfa_secret === null || ! Totp::verify($user->mfa_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => ['That code is not valid.']]);
        }

        $codes = collect(range(1, 8))->map(fn () => Str::upper(Str::random(5).'-'.Str::random(5)))->all();
        $user->forceFill(['mfa_enabled_at' => now(), 'mfa_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes)])->save();

        // Every session issued before the second factor existed ends; this one is replaced by a full one.
        $current = $request->user()->currentAccessToken();
        PersonalAccessToken::where('tokenable_type', User::class)->where('tokenable_id', $user->id)->delete();
        $token = $user->createToken('platform', ['platform'], now()->addHours(config('platform.session_hours')));

        $this->audit->log('platform.mfa_enabled', $user);

        return ApiResponse::success([
            'token' => $token->plainTextToken,
            // Shown once. Each works one time in place of a code.
            'recovery_codes' => $codes,
            'replaced_session' => $current instanceof PersonalAccessToken,
        ]);
    }

    public function disableMfa(Request $request)
    {
        $user = $request->user();
        $data = $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'string']]);

        if (config('platform.require_mfa')) {
            throw ValidationException::withMessages(['code' => ['A second factor is required for platform accounts.']]);
        }

        if (! Hash::check($data['password'], $user->password) || ! $this->passesSecondFactor($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => ['That code is not valid.']]);
        }

        $user->forceFill(['mfa_secret' => null, 'mfa_enabled_at' => null, 'mfa_recovery_codes' => null])->save();
        $this->audit->log('platform.mfa_disabled', $user);

        return ApiResponse::success(null, 'Second factor removed.');
    }

    public function me(Request $request)
    {
        $user = $request->user();

        return ApiResponse::success([
            'user' => ['id' => $user->uuid, 'name' => $user->name, 'email' => $user->email],
            'is_super_admin' => true,
            'mfa_enabled' => $user->mfa_enabled_at !== null,
            'mfa_enrolment_required' => ! $user->tokenCan('platform'),
        ]);
    }

    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return ApiResponse::success(null, 'Signed out.');
    }

    /** Same rules as a company account: per-account lockout, each code accepted once, a recovery code works once. */
    private function passesSecondFactor(User $user, string $code): bool
    {
        return app(SecondFactor::class)->passes($user, $code);
    }
}
