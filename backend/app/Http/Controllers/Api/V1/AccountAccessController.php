<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\EmailVerification;
use App\Domain\Auth\SecondFactor;
use App\Domain\Billing\PlanLimits;
use App\Domain\Mail\AccountEmail;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\PasswordLink;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The public side of getting into an account: reading and accepting an invitation, asking for a reset link, reading
 * and using a password link. Every answer that could tell a stranger whether an email is registered is the same either
 * way. Tokens are compared by hash and are single-use.
 */
class AccountAccessController extends Controller
{
    public function __construct(private AuditLogger $audit, private AccountEmail $email, private TenantContext $context) {}

    public function invitation(string $token)
    {
        $invitation = Invitation::findByToken($token);
        abort_unless($invitation?->isPending(), 404, 'This invitation is not valid.');
        $tenant = Tenant::with('branding')->findOrFail($invitation->tenant_id);

        return ApiResponse::success([
            'company' => $tenant->branding?->company_name ?? $tenant->name,
            'email' => $invitation->email,
            'name' => $invitation->name,
            'role' => $invitation->role,
            // Someone already on Mohtam with this (proven) email confirms their own password instead of choosing one.
            'has_account' => $this->verifiedAccounts($invitation->email)->isNotEmpty(),
        ]);
    }

    public function accept(Request $request, string $token)
    {
        $invitation = Invitation::findByToken($token);
        abort_unless($invitation?->isPending(), 404, 'This invitation is not valid.');
        $tenant = Tenant::findOrFail($invitation->tenant_id);
        abort_unless($tenant->is_active, 404, 'This invitation is not valid.');

        $existing = $this->verifiedAccounts($invitation->email);

        if ($existing->isNotEmpty()) {
            $data = $request->validate(['password' => ['required', 'string']]);

            if (! $existing->contains(fn (User $u) => Hash::check($data['password'], $u->password))) {
                $this->audit->logForTenant($invitation->tenant_id, 'invitation.password_refused', null, $invitation, ['ip' => $request->ip()]);

                throw ValidationException::withMessages(['password' => ['That is not the password of your account.']]);
            }

            $name = $existing->first()->name;
        } else {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);
            $name = $data['name'];
        }

        $user = $this->context->withTenant($tenant, function () use ($invitation, $tenant, $data, $name) {
            return DB::transaction(function () use ($invitation, $tenant, $data, $name) {
                if (User::where('tenant_id', $tenant->id)->where('email', $invitation->email)->exists()) {
                    throw ValidationException::withMessages(['email' => ['You are already on this team.']]);
                }

                // This invitation already holds its seat; accepting it takes no new one — unless the plan shrank since.
                PlanLimits::ensureSeatFor($tenant, $invitation->role, 0);

                $user = User::create([
                    'tenant_id' => $tenant->id,
                    'name' => $name,
                    'email' => $invitation->email,
                    'password' => $data['password'],
                    'role' => $invitation->role,
                    'access_role_id' => $invitation->access_role_id,
                    'all_projects' => $invitation->all_projects,
                    'phone' => $invitation->phone,
                    'is_active' => true,
                ]);
                // The link reached this inbox: the address is proven.
                $user->forceFill(['email_verified_at' => now()])->save();

                if ($invitation->project_ids) {
                    $user->projects()->sync(Project::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereIn('uuid', $invitation->project_ids)->pluck('id')->all());
                }

                $invitation->forceFill(['accepted_at' => now(), 'accepted_user_id' => $user->id])->save();
                $this->audit->logForTenant($tenant->id, 'invitation.accepted', $user, $user, ['email' => $user->email, 'role' => $user->role?->value]);

                return $user;
            });
        });

        return ApiResponse::created(app(AuthController::class)->issueSession($user->fresh(), $request));
    }

    /** Always the same answer, registered or not. */
    public function forgot(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $email = strtolower($data['email']);

        $account = User::query()->whereNotNull('tenant_id')->where('email', $email)->where('is_active', true)
            ->whereHas('tenant', fn ($q) => $q->where('is_active', true))
            ->orderByRaw('last_login_at desc nulls last')->first();

        // After the answer has left, so how long it took says nothing either. Three a quarter hour per address: nobody's inbox is a target.
        if ($account && RateLimiter::attempt('forgot:'.hash('sha256', $email), 3, fn () => true, 900)) {
            $ip = $request->ip();

            $sent = false;

            app()->terminating(function () use ($account, $email, $ip, &$sent) {
                if ($sent) {
                    return;
                }

                $sent = true;
                [, $token] = PasswordLink::issue($email, 'reset', $ip);
                $this->email->passwordLink($email, $account->name, 'reset', $token, Tenant::with('branding')->find($account->tenant_id), $account->locale);
                $this->audit->logForTenant($account->tenant_id, 'auth.password_reset_requested', $account, $account, ['ip' => $ip]);
            });
        }

        return ApiResponse::success(null, 'If that email is registered, a link is on its way.');
    }

    public function link(string $token)
    {
        $link = PasswordLink::findUsable($token);
        abort_unless($link !== null, 404, 'This link is not valid.');

        return ApiResponse::success(['email' => $link->email, 'purpose' => $link->purpose]);
    }

    /**
     * The person chooses a password. It becomes theirs in every company they belong to with that email (the link proved
     * the inbox), every other session they had ends, and they are signed in.
     */
    public function reset(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $link = PasswordLink::findUsable($data['token']);

        if ($link === null) {
            throw ValidationException::withMessages(['token' => ['This link has expired or was already used.']]);
        }

        $accounts = User::query()->whereNotNull('tenant_id')->where('email', $link->email)->where('is_active', true)->get();

        if ($accounts->isEmpty()) {
            throw ValidationException::withMessages(['token' => ['This link has expired or was already used.']]);
        }

        // The account the person actually uses. A row another company's admin added under this address stays unproven, so it links to nothing.
        $user = $accounts->sortByDesc(fn (User $u) => $u->last_login_at?->timestamp ?? 0)->first();

        DB::transaction(function () use ($accounts, $data, $link, $request, $user) {
            $link->forceFill(['used_at' => now()])->save();

            foreach ($accounts as $account) {
                $account->forceFill(['password' => $data['password'], 'email_verified_at' => $account->email_verified_at ?? ($account->is($user) ? now() : null)])->save();
                PersonalAccessToken::where('tokenable_type', User::class)->where('tokenable_id', $account->id)->delete();
                $this->audit->logForTenant($account->tenant_id, $link->purpose === 'setup' ? 'auth.password_set' : 'auth.password_reset', $account, $account, ['ip' => $request->ip()]);
            }
        });

        // A reset link proves the inbox, not the authenticator app: an account with a second factor signs in with it.
        if (app(SecondFactor::class)->enabled($user->fresh())) {
            return ApiResponse::success(['password_changed' => true, 'mfa_required' => true]);
        }

        return ApiResponse::success(app(AuthController::class)->issueSession($user->fresh(), $request));
    }

    /** Signed-in: send this person a link that proves their own address. */
    public function sendVerification(Request $request)
    {
        $user = $request->user();

        if ($user->email_verified_at !== null) {
            return ApiResponse::success(['verified' => true]);
        }

        $tenant = $user->tenant;
        abort_if($tenant === null, 404);

        $this->email->verification($user, EmailVerification::token($user), Tenant::with('branding')->find($tenant->id));
        $this->audit->log('auth.email_verification_sent', $user, $user, ['ip' => $request->ip()]);

        return ApiResponse::success(['verified' => false], 'A link is on its way.');
    }

    /** The link: this one account's address, as it is now. Opening it twice changes nothing. */
    public function verifyEmail(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:512']]);
        $user = EmailVerification::accountFor($data['token']);

        if ($user === null) {
            throw ValidationException::withMessages(['token' => ['This link has expired or is not valid.']]);
        }

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
            $this->audit->logForTenant($user->tenant_id, 'auth.email_verified', $user, $user, ['ip' => $request->ip()]);
        }

        return ApiResponse::success(['verified' => true]);
    }

    /** Accounts with this email whose address has been proven, in companies that are open. */
    private function verifiedAccounts(string $email)
    {
        return User::query()->whereNotNull('tenant_id')->where('email', strtolower($email))
            ->where('is_active', true)->whereNotNull('email_verified_at')
            ->whereHas('tenant', fn ($q) => $q->where('is_active', true))->get();
    }
}
