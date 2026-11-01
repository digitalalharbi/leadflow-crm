<?php

namespace App\Domain\Tracking;

/**
 * A short code a landing page puts in the WhatsApp message it prepares ("Ref: ABC123"), the only way a conversation that starts
 * outside the browser can be tied back to the visit, the page and the campaign. Derived from the session; says nothing about it.
 */
final class WhatsappReference
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function for(string $sessionId): string
    {
        $hash = hash_hmac('sha256', $sessionId, (string) config('app.key'), true);
        $ref = '';

        for ($i = 0; $i < 6; $i++) {
            $ref .= self::ALPHABET[ord($hash[$i]) % 32];
        }

        return $ref;
    }

    /** The reference a message carries, if it carries one. */
    public static function in(?string $message): ?string
    {
        return $message !== null && preg_match('/\bRef:\s*([A-HJ-NP-Z2-9]{6})\b/u', $message, $m) ? $m[1] : null;
    }
}
