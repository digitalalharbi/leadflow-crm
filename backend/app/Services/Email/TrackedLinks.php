<?php

namespace App\Services\Email;

/**
 * Turns the links in a message into links that can say they were pressed.
 *
 * The redirect carries the destination in the URL, which is an open redirect
 * unless the destination is signed: anybody could mint a link on our domain
 * that sends a customer anywhere. So the signature covers the token and the
 * URL together, and a link we did not write does not redirect.
 */
class TrackedLinks
{
    public function sign(string $token, string $url): string
    {
        return substr(hash_hmac('sha256', $token.'|'.$url, (string) config('app.key')), 0, 32);
    }

    public function verify(string $token, string $url, string $signature): bool
    {
        return hash_equals($this->sign($token, $url), $signature)
            && preg_match('#^https?://#i', $url) === 1;
    }

    public function clickUrl(string $token, string $url): string
    {
        return route('email.click', [
            'token' => $token,
            'u' => rtrim(strtr(base64_encode($url), '+/', '-_'), '='),
            's' => $this->sign($token, $url),
        ]);
    }

    public function decode(string $encoded): ?string
    {
        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $raw === false ? null : $raw;
    }

    /**
     * Plain text to HTML, with every web address a tracked link.
     *
     * Escaped first: the body is whatever a rep or a template typed, and an
     * email is a document a customer's mail client will render.
     */
    public function toHtml(string $text, string $token): string
    {
        $escaped = e($text);

        $linked = preg_replace_callback(
            '#https?://[^\s<>"\']+#i',
            function (array $m) use ($token) {
                // `e()` turned & into &amp; — undo it for the destination only.
                $url = html_entity_decode(rtrim($m[0], '.,;:!?)،؛'), ENT_QUOTES);
                $trail = substr($m[0], strlen(rtrim($m[0], '.,;:!?)،؛')));

                return '<a href="'.e($this->clickUrl($token, $url)).'">'.e($url).'</a>'.$trail;
            },
            $escaped,
        );

        return nl2br($linked ?? $escaped, false);
    }
}
