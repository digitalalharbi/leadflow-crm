<?php

namespace App\Domain\Auth;

use App\Models\User;

/**
 * A link that proves one account's address. Signed rather than stored: it names the account and the address it was sent
 * to, so it stops working when the address changes, and using it twice changes nothing.
 */
class EmailVerification
{
    public const HOURS = 24;

    public static function token(User $user): string
    {
        $payload = self::encode(json_encode(['u' => $user->uuid, 'e' => hash('sha256', (string) $user->email), 'x' => now()->addHours(self::HOURS)->timestamp]));

        return $payload.'.'.self::signature($payload);
    }

    public static function accountFor(string $token): ?User
    {
        [$payload, $signature] = array_pad(explode('.', $token, 2), 2, '');

        if (! hash_equals(self::signature($payload), $signature)) {
            return null;
        }

        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);

        if (! is_array($claims) || ($claims['x'] ?? 0) < now()->timestamp) {
            return null;
        }

        $user = User::query()->whereNotNull('tenant_id')->where('uuid', $claims['u'] ?? '')->where('is_active', true)->first();

        return $user !== null && hash_equals(hash('sha256', (string) $user->email), (string) ($claims['e'] ?? '')) ? $user : null;
    }

    public static function url(string $token): string
    {
        return rtrim((string) config('integrations.app_url'), '/').'/verify-email?token='.$token;
    }

    private static function signature(string $payload): string
    {
        return self::encode(hash_hmac('sha256', 'email-verification:'.$payload, (string) config('app.key'), true));
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
