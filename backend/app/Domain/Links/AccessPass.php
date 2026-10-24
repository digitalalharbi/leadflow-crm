<?php

namespace App\Domain\Links;

use App\Models\ShortLink;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Proof that somebody was let in to a restricted link: they asked for a code at an allowed email, and typed it.
 *
 * The code is six digits, kept only as a hash, good for ten minutes and five tries. The pass that follows is signed with the
 * application key, names the link and the email, and expires; it is no use on any other link. Asking for a code answers the
 * same whether or not the email was allowed, so the form cannot be used to find out who is.
 */
class AccessPass
{
    private const MAX_TRIES = 5;

    /** @return string|null the code, only when the email may have one — the caller sends it, and says "sent" either way */
    public function request(ShortLink $link, string $email): ?string
    {
        $email = strtolower(trim($email));

        if (! $link->allowsEmail($email)) {
            return null;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put($this->key($link, $email), ['hash' => hash('sha256', $code.config('app.key')), 'tries' => 0], now()->addMinutes((int) config('links.otp_minutes')));

        return $code;
    }

    /** @return string|null a pass, when the code is right */
    public function verify(ShortLink $link, string $email, string $code): ?string
    {
        $email = strtolower(trim($email));
        $key = $this->key($link, $email);
        $held = Cache::get($key);

        if ($held === null || $held['tries'] >= self::MAX_TRIES) {
            return null;
        }

        if (! hash_equals($held['hash'], hash('sha256', trim($code).config('app.key')))) {
            Cache::put($key, ['tries' => $held['tries'] + 1] + $held, now()->addMinutes((int) config('links.otp_minutes')));

            return null;
        }

        Cache::forget($key);

        return $this->issue($link, $email);
    }

    public function issue(ShortLink $link, string $email): string
    {
        $expires = now()->addHours((int) config('links.otp_session_hours'))->timestamp;
        $payload = rtrim(strtr(base64_encode(json_encode([$link->id, strtolower($email), $expires])), '+/', '-_'), '=');

        return $payload.'.'.hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    public function valid(ShortLink $link, ?string $pass): bool
    {
        if (! $pass || substr_count($pass, '.') !== 1) {
            return false;
        }

        [$payload, $signature] = explode('.', $pass);

        if (! hash_equals(hash_hmac('sha256', $payload, (string) config('app.key')), $signature)) {
            return false;
        }

        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);

        return is_array($data) && (int) $data[0] === $link->id && (int) $data[2] > now()->timestamp && $link->allowsEmail((string) $data[1]);
    }

    private function key(ShortLink $link, string $email): string
    {
        return 'otp:'.$link->tenant_id.':'.$link->id.':'.Str::substr(hash('sha256', $email), 0, 20);
    }
}
