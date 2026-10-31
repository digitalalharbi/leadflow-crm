<?php

namespace App\Domain\Auth;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * An authenticator-app second factor (TOTP) with one-time recovery codes, for any account — the platform owner and a
 * company's people alike. Never SMS: a number can be moved to another SIM; an authenticator app cannot be.
 */
class SecondFactor
{
    public function enabled(User $user): bool
    {
        return $user->mfa_enabled_at !== null && $user->mfa_secret !== null;
    }

    /** A code from the app, or one unused recovery code (spent on use). */
    public function passes(User $user, string $code): bool
    {
        $code = trim($code);

        if ($user->mfa_secret !== null && Totp::verify($user->mfa_secret, $code)) {
            return true;
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
        if ($user->mfa_secret === null || ! Totp::verify($user->mfa_secret, trim($code))) {
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
