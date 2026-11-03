<?php

namespace App\Domain\Auth;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * An authenticator-app second factor (TOTP) with one-time recovery codes, for any account — the platform owner and a
 * company's people alike. Never SMS: a number can be moved to another SIM; an authenticator app cannot be.
 *
 * Six digits are guessable by volume, so each account allows MAX_FAILURES wrong codes per LOCKOUT_SECONDS, whatever the
 * address they come from; and a code that has been accepted once is refused after that (no replay of an observed code).
 */
class SecondFactor
{
    public const MAX_FAILURES = 5;

    public const LOCKOUT_SECONDS = 900;

    public function enabled(User $user): bool
    {
        return $user->mfa_enabled_at !== null && $user->mfa_secret !== null;
    }

    /**
     * A code from the app (each accepted once), or one unused recovery code (spent on use).
     *
     * @throws ValidationException while the account is locked out by too many wrong codes
     */
    public function passes(User $user, string $code): bool
    {
        $limiter = 'mfa-failures:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($limiter, self::MAX_FAILURES)) {
            throw ValidationException::withMessages(['code' => ['Too many wrong codes. Try again in '.(int) ceil(RateLimiter::availableIn($limiter) / 60).' minutes.']]);
        }

        if ($this->matches($user, trim($code))) {
            RateLimiter::clear($limiter);

            return true;
        }

        RateLimiter::hit($limiter, self::LOCKOUT_SECONDS);

        return false;
    }

    private function matches(User $user, string $code): bool
    {
        $step = $user->mfa_secret !== null ? Totp::matchingStep($user->mfa_secret, $code) : null;

        if ($step !== null) {
            // Atomic: of two requests carrying the same code, only one is let through.
            return Cache::add('mfa-used:'.$user->getKey().':'.$step, true, 120);
        }

        $codes = $user->mfa_recovery_codes ?? [];

        foreach ($codes as $index => $hash) {
            if (Hash::check(strtoupper($code), $hash)) {
                unset($codes[$index]);
                $user->forceFill(['mfa_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    /** Starts enrolment with a fresh secret; it does nothing until confirmed with a code. */
    public function begin(User $user, string $issuer): array
    {
        $secret = Totp::generateSecret();
        $user->forceFill(['mfa_secret' => $secret, 'mfa_enabled_at' => null])->save();

        return ['secret' => $secret, 'otpauth_uri' => Totp::uri($secret, $user->email, $issuer)];
    }

    /** @return list<string>|null the recovery codes, shown once; null when the code does not match the pending secret */
    public function confirm(User $user, string $code): ?array
    {
        // Already on: confirming again would hand out fresh recovery codes for a session's asking.
        if ($this->enabled($user) || $user->mfa_secret === null || ! Totp::verify($user->mfa_secret, trim($code))) {
            return null;
        }

        $codes = collect(range(1, 8))->map(fn () => Str::upper(Str::random(5).'-'.Str::random(5)))->all();
        $user->forceFill(['mfa_enabled_at' => now(), 'mfa_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes)])->save();

        return $codes;
    }

    public function remove(User $user): void
    {
        $user->forceFill(['mfa_secret' => null, 'mfa_enabled_at' => null, 'mfa_recovery_codes' => null])->save();
    }
}
