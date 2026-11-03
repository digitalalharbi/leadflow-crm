<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A one-use, expiring link to choose a password: `setup` for an account that never had one of its own (a new company's
 * owner), `reset` for a forgotten one. Keyed by email, because the same person may belong to several companies and the
 * password they choose is theirs in each. Only the token's hash is stored.
 */
class PasswordLink extends Model
{
    public const UPDATED_AT = null;

    public const LIFETIME = ['setup' => 60 * 24 * 7, 'reset' => 60];

    protected $fillable = ['email', 'purpose', 'token_hash', 'expires_at', 'used_at', 'ip'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    /** @return array{0: self, 1: string} */
    public static function issue(string $email, string $purpose, ?string $ip = null): array
    {
        // A new link replaces any open one for the same address.
        static::where('email', strtolower($email))->whereNull('used_at')->update(['used_at' => now()]);

        $token = Str::random(48);
        $link = static::create([
            'email' => strtolower($email),
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(self::LIFETIME[$purpose]),
            'ip' => $ip,
        ]);

        return [$link, $token];
    }

    public static function findUsable(string $token): ?self
    {
        return static::where('token_hash', hash('sha256', $token))->whereNull('used_at')->where('expires_at', '>', now())->first();
    }

    public static function url(string $token): string
    {
        return rtrim((string) config('integrations.app_url'), '/').'/reset-password?token='.$token;
    }
}
