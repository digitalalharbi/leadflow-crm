<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AccessResolver;
use App\Domain\Access\Permissions;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\BrandingResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Leads\PhoneLanguage;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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

        $query = User::query()->with('tenant.branding', 'tenant.settings')->where('email', $email);

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
        $candidates = $query->orderByDesc('is_active')->orderBy('id')->get();

        $user = $candidates->first(fn (User $candidate) => Hash::check($password, $candidate->password));

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

        $user->loadMissing('tenant.branding', 'tenant.settings');

        // One token per device; logging in again elsewhere does not kill this session.
        $token = $user->createToken('spa-'.substr(md5($request->userAgent() ?? 'unknown'), 0, 8))->plainTextToken;

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $this->audit->log('auth.login', $user, null, ['ip' => $request->ip()]);

        return ApiResponse::success([
            'token' => $token,
            'user' => new UserResource($user),
            'branding' => $user->tenant ? new BrandingResource($user->tenant) : null,
            'is_super_admin' => $user->isSuperAdmin(),
            // What this person may do (the interface hides the rest; the API refuses it), and which
            // projects they are limited to (null: all of them).
            'permissions' => Permissions::forUser($user),
            'hidden_data' => Permissions::hiddenData($user),
            'hidden_elements' => Permissions::hiddenElements($user),
            'access_role' => AccessResolver::customRole($user)?->uuid,
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
            // What this person may do (the interface hides the rest; the API refuses it), and which
            // projects they are limited to (null: all of them).
            'permissions' => Permissions::forUser($user),
            'hidden_data' => Permissions::hiddenData($user),
            'hidden_elements' => Permissions::hiddenElements($user),
            'access_role' => AccessResolver::customRole($user)?->uuid,
            'project_scope' => $user->isProjectScoped() ? $user->projects()->pluck('projects.uuid')->all() : null,
            // The language this workspace itself works in — see login, which
            // sends the same field; the SPA re-hydrates from here on reload.
            'workspace_language' => app(PhoneLanguage::class)
                ->forCallingCode($user->tenant?->settings?->default_country_code),
            // Lets the SPA offer the sales-demo helpers. False in production, so
            // the controls simply do not exist for a real customer.
            'demo_mode' => ! app()->environment('production'),
        ]);
    }

    public function logout(Request $request)
    {
        // A cookie-authenticated request carries a TransientToken, which has no
        // row to delete — only revoke a real personal access token.
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

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

        return ApiResponse::success(null, 'Signed out of all devices.');
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
