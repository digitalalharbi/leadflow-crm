<?php

namespace App\Domain\Tracking;

use Illuminate\Support\Str;

/**
 * What can be known about a visitor without knowing who they are.
 *
 * An opaque id that stitches a click to the form that follows it, the device class and browser
 * from the user agent, and a salted hash of the address — enough to count unique visitors and
 * tell a phone from a laptop, none of it personal. Location is deliberately not collected: a
 * city from an IP address is a guess about a person, and the product can do without it.
 */
class Visitor
{
    public const COOKIE = 'mt_vid';

    public static function newId(): string
    {
        return 'v_'.Str::lower(Str::random(20));
    }

    /** Only ids this class issued are trusted back: a made-up id must not become a key. */
    public static function clean(?string $id): ?string
    {
        $id = trim((string) $id);

        return preg_match('/^v_[a-z0-9]{20}$/', $id) === 1 ? $id : null;
    }

    public static function ipHash(?string $ip): ?string
    {
        return filled($ip) ? hash_hmac('sha256', (string) $ip, (string) config('app.key')) : null;
    }

    /** @return array{device:?string, browser:?string, os:?string} */
    public static function agent(?string $userAgent): array
    {
        $ua = strtolower((string) $userAgent);

        if ($ua === '') {
            return ['device' => null, 'browser' => null, 'os' => null];
        }

        $device = match (true) {
            str_contains($ua, 'ipad') || str_contains($ua, 'tablet') => 'tablet',
            str_contains($ua, 'mobi') || str_contains($ua, 'iphone') || str_contains($ua, 'android') => 'mobile',
            default => 'desktop',
        };

        $browser = match (true) {
            str_contains($ua, 'edg/') => 'Edge',
            str_contains($ua, 'opr/') || str_contains($ua, 'opera') => 'Opera',
            str_contains($ua, 'samsungbrowser') => 'Samsung',
            str_contains($ua, 'chrome') || str_contains($ua, 'crios') => 'Chrome',
            str_contains($ua, 'firefox') || str_contains($ua, 'fxios') => 'Firefox',
            str_contains($ua, 'safari') => 'Safari',
            default => 'Other',
        };

        $os = match (true) {
            str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ios') => 'iOS',
            str_contains($ua, 'android') => 'Android',
            str_contains($ua, 'windows') => 'Windows',
            str_contains($ua, 'mac os') || str_contains($ua, 'macintosh') => 'macOS',
            str_contains($ua, 'linux') => 'Linux',
            default => 'Other',
        };

        return ['device' => $device, 'browser' => $browser, 'os' => $os];
    }
}
