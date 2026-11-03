<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AccessResolver;
use App\Domain\Access\Destination;
use App\Domain\Access\Permissions;
use App\Domain\Auth\SecondFactor;
use App\Domain\Billing\SubscriptionSummary;
use App\Domain\Mail\AccountEmail;
use App\Http\Controllers\Api\V1\Platform\ImpersonationController;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\BrandingResource;
use App\Http\Resources\UserResource;
use App\Models\Impersonation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Leads\PhoneLanguage;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Token login for the SPA. The tenant is derived from the account, never
     * from anything the client sends.
     */
    public function login(LoginRequest $request)
    {
        $email = strtolower($request->string('email')->toString());

        // A company's login finds a company's people only. The platform owner signs in at the platform's own door.
        $query = User::query()->with('tenant.branding', 'tenant.settings')->whereNotNull('tenant_id')->where('email', $email);

        // Optional subdomain hint narrows the lookup when the same person has
        // accounts in more than one organisation.
        if ($slug = $request->input('tenant')) {
            $query->whereHas('tenant', fn ($q) => $q->where('slug', $slug));
        }

        $password = $request->string('password')->toString();

        /*
         * An email is unique per tenant, not globally, so this can legitimately
         * match several accounts. Taking the first row would let one tenant's
         * account shadow another's — check the password against each candidate
         * and prefer an active one, instead of guessing.
         */
        // One person may hold an account in several companies. Of the ones this password opens, an active one in an
        // open company, the one used most recently first.
        $candidates = $query->orderByRaw('last_login_at desc nulls last')->orderBy('id')->get();
        $matching = $candidates->filter(fn (User $candidate) => Hash::check($password, $candidate->password));
        $user = $matching->first(fn (User $u) => $u->is_active && $u->tenant?->is_active)
            ?? $matching->first(fn (User $u) => $u->is_active)
            ?? $matching->first();

        // One door for everybody. The platform's own account continues on its stricter path, password and second factor.
        if (! $user && User::query()->whereNull('tenant_id')->where('email', $email)->get()->contains(fn (User $owner) => Hash::check($password, $owner->password))) {
            return ApiResponse::success(['platform' => true]);
        }

        if (! $user) {
            /*
             * No candidate matched — which includes the case where the email is
             * unknown and no hash was computed at all. Answering that in a few
             * microseconds tells an attacker the address does not exist, so do
             * the work anyway before refusing.
             */
            if ($candidates->isEmpty()) {
                // Hashing costs the same as the check we did not get to perform,
                // and tracks the configured cost factor on its own.
                Hash::make($password);
            }

            $this->recordFailedLogin($email, $candidates, $request);

            // Identical message either way — never reveal whether an email exists.
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated.'],
            ]);
        }

        if (! $user->isSuperAdmin() && ! $user->tenant?->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This organisation has been suspended.'],
            ]);
        }

        // The password was right; an account with a second factor still needs its code before any session exists.
        if ($refused = $this->secondFactorStep($user, $request->input('code'), $request)) {
            return $refused;
        }

        // Not remembered: this session lasts a working day, not the month.
        return ApiResponse::success($this->issueSession($user, $request, expiresAt: $request->boolean('remember', true) ? null : now()->addHours(12)));
    }

    /**
     * Null when the account has no second factor or the code passed; otherwise the answer to give: "a code is needed"
     * (no session, no hint whether the password was the problem — it was not) or a refusal for a wrong code.
     */
    public function secondFactorStep(User $user, mixed $code, Request $request): ?JsonResponse
    {
        $factor = app(SecondFactor::class);

        if (! $factor->enabled($user)) {
            return null;
        }

        if (blank($code)) {
            return ApiResponse::success(['mfa_required' => true]);
        }

        if (! $factor->passes($user, (string) $code)) {
            $this->audit->logForTenant((int) $user->tenant_id, 'auth.mfa_failed', $user, $user, ['ip' => $request->ip()]);

            throw ValidationException::withMessages(['code' => ['That code is not valid.']]);
        }

        return null;
    }

    /**
     * A wrong password is a security event. Each company that has this address sees it in its own audit trail (against the
     * account, never with the password); an address nobody has goes to the log only as a hash, so the log is not a list of
     * guessed emails.
     */
    private function recordFailedLogin(string $email, $candidates, Request $request): void
    {
        foreach ($candidates->unique('tenant_id') as $candidate) {
            $this->audit->logForTenant((int) $candidate->tenant_id, 'auth.login_failed', $candidate, $candidate, ['ip' => $request->ip()]);
        }

        Log::warning('auth.login_failed', [
            'email_hash' => hash('sha256', $email),
            'known' => $candidates->isNotEmpty(),
            'ip' => $request->ip(),
        ]);
    }

    /**
     * A new session for this account and the payload the SPA boots from. Used by the login, an accepted invitation, a
     * password link and a workspace switch — every way into a company ends the same way.
     */
    public function issueSession(User $user, Request $request, string $action = 'auth.login', ?\DateTimeInterface $expiresAt = null): array
    {
        $user->loadMissing('tenant.branding', 'tenant.settings');

        // One token per device; logging in again elsewhere does not kill this session.
        $issued = $user->createToken('spa-'.substr(md5($request->userAgent() ?? 'unknown'), 0, 8), ['*'], $expiresAt);
        // What the person sees in their list of signed-in devices.
        $issued->accessToken->forceFill(['ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255)])->save();
        $token = $issued->plainTextToken;

        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        $this->noticeNewDevice($user, $request, $action);

        $this->audit->log($action, $user, null, ['ip' => $request->ip()]);

        // Built against this account's company, whatever the request's own context was.
        return app(TenantContext::class)->withTenant($user->tenant, fn () => [
            'token' => $token,
            'user' => new UserResource($user),
            'branding' => $user->tenant ? new BrandingResource($user->tenant) : null,
            'is_super_admin' => $user->isSuperAdmin(),
            'home' => Destination::for($user),
            // What this person may do (the interface hides the rest; the API refuses it), and which
            // projects they are limited to (null: all of them).
            'permissions' => Permissions::forUser($user),
            'hidden_data' => Permissions::hiddenData($user),
            'hidden_elements' => Permissions::hiddenElements($user),
            'access_role' => AccessResolver::customRole($user)?->uuid,
            // The plan's features, limits and lifecycle: the interface agrees with what the server enforces.
            'subscription' => SubscriptionSummary::for($user->tenant),
            'project_scope' => $user->isProjectScoped() ? $user->projects()->pluck('projects.uuid')->all() : null,
            /*
             * The language this workspace itself works in, read off its default
             * country code by the same table that reads a customer's number.
             *
             * The interface uses it to stay quiet: a Riyadh team does not need
             * every lead labelled "Arabic", and the one labelled "Urdu" is the
             * one worth seeing.
             */
            'workspace_language' => app(PhoneLanguage::class)
                ->forCallingCode($user->tenant?->settings?->default_country_code),
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user()->loadMissing('tenant.branding', 'tenant.settings');

        return ApiResponse::success([
            'user' => new UserResource($user),
            'branding' => $user->tenant ? new BrandingResource($user->tenant) : null,
            'is_super_admin' => $user->isSuperAdmin(),
            'home' => Destination::for($user),
            // What this person may do (the interface hides the rest; the API refuses it), and which
            // projects they are limited to (null: all of them).
            'permissions' => Permissions::forUser($user),
            'hidden_data' => Permissions::hiddenData($user),
            'hidden_elements' => Permissions::hiddenElements($user),
            'access_role' => AccessResolver::customRole($user)?->uuid,
            // The plan's features, limits and lifecycle: the interface agrees with what the server enforces.
            'subscription' => ($tenant = app(TenantContext::class)->get() ?? $user->tenant) ? SubscriptionSummary::for($tenant) : null,
            'project_scope' => $user->isProjectScoped() ? $user->projects()->pluck('projects.uuid')->all() : null,
            // The language this workspace itself works in — see login, which
            // sends the same field; the SPA re-hydrates from here on reload.
            'workspace_language' => app(PhoneLanguage::class)
                ->forCallingCode($user->tenant?->settings?->default_country_code),
            // A support session says so on every screen, with how long it has left.
            'impersonation' => $this->impersonation($request),
            // Lets the SPA offer the sales-demo helpers. False in production, so
            // the controls simply do not exist for a real customer.
            'demo_mode' => ! app()->environment('production'),
        ]);
    }

    private function impersonation(Request $request): ?array
    {
        $token = $request->user()->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || $token->name !== ImpersonationController::TOKEN_NAME) {
            return null;
        }

        $session = Impersonation::where('token_id', $token->id)->first();

        return $session ? ['reason' => $session->reason, 'expires_at' => $session->expires_at->toIso8601String()] : null;
    }

    public function logout(Request $request)
    {
        // A cookie-authenticated request carries a TransientToken, which has no
        // row to delete — only revoke a real personal access token.
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        $this->audit->log('auth.logout', $request->user(), null, ['ip' => $request->ip()]);

        // A pure token request has no session store at all; asking for one throws.
        if ($request->hasSession()) {
            $request->session()->invalidate();
        }

        return ApiResponse::success(null, 'Signed out.');
    }

    /** Revokes every token — used when a password changes or a device is lost. */
    public function logoutAll(Request $request)
    {
        $request->user()->tokens()->delete();
        $this->linkedAccounts($request->user())->each(fn (User $account) => $account->tokens()->delete());
        $this->audit->log('auth.logout_all', $request->user(), null, ['ip' => $request->ip()]);

        return ApiResponse::success(null, 'Signed out of all devices.');
    }

    /**
     * A sign-in from a device this account has not used is worth an email; one from a known device is not. Moving between
     * companies is not a sign-in, and the very first sign-in has nothing to compare with.
     */
    private function noticeNewDevice(User $user, Request $request, string $action): void
    {
        if ($action === 'auth.workspace_switch') {
            return;
        }

        $device = hash('sha256', (string) $request->userAgent());
        $known = (array) ($user->known_devices ?? []);

        if (in_array($device, $known, true)) {
            return;
        }

        $user->forceFill(['known_devices' => array_slice([...$known, $device], -20)])->saveQuietly();

        if ($known !== []) {
            app(AccountEmail::class)->securityNotice($user, 'new_device');
        }
    }

    /** This person's accounts in other companies: the same email, proven on both sides. */
    private function linkedAccounts(User $user)
    {
        if ($user->tenant_id === null || $user->email_verified_at === null) {
            return collect();
        }

        return User::query()->whereNotNull('tenant_id')->where('email', $user->email)->whereKeyNot($user->id)->whereNotNull('email_verified_at')->get();
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->update(['password' => $data['password']]);
        app(AccountEmail::class)->securityNotice($user, 'password_changed');

        // One person, one password: an old one must not stay good in their other companies, or be a way back here by switching.
        $this->linkedAccounts($user)->each(function (User $account) use ($data) {
            $account->forceFill(['password' => $data['password']])->save();
            $account->tokens()->delete();
            $this->audit->logForTenant((int) $account->tenant_id, 'auth.password_changed', $account, $account, ['from_linked_account' => true]);
        });

        // Invalidate every other session; keep the one making the change.
        $current = $user->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->id : null;

        $user->tokens()
            ->when($currentId !== null, fn ($q) => $q->where('id', '!=', $currentId))
            ->delete();

        $this->audit->log('auth.password_changed', $user, $user);

        return ApiResponse::success(null, 'Password updated.');
    }
}
