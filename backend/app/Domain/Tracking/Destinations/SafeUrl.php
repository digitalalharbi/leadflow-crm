<?php

namespace App\Domain\Tracking\Destinations;

/**
 * An address a server will call on a workspace's say-so.
 *
 * Only https, only a name (never an IP literal), never somewhere inside our own network: a form that lets a
 * customer-supplied URL be fetched is a way to reach what only the server can reach. Checked when the
 * destination is saved and again, against what the name resolves to, when it is called.
 */
class SafeUrl
{
    public static function isAcceptable(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower($parts['host']);

        return ! filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)
            && str_contains($host, '.')
            && ! preg_match('/(^|\.)(localhost|local|internal|lan|home|corp|intranet)$/', $host)
            && mb_strlen($url) <= 500;
    }

    /** Whether the name resolves only to public addresses. In tests nothing is resolved: no network is touched. */
    public static function resolvesPublicly(string $url): bool
    {
        if (app()->environment('testing')) {
            return true;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $ips = array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, @dns_get_record($host, DNS_A | DNS_AAAA) ?: []);

        if ($ips === []) {
            return false;
        }

        foreach (array_filter($ips) as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }
}
