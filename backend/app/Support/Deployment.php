<?php

namespace App\Support;

/**
 * What this process actually is: a developer's machine, a staging server or
 * production — decided from what is observable, never from what somebody
 * wrote in a document.
 *
 * "Production" needs all three: the application is told it is production, its
 * configured address is HTTPS, and that address is a name a customer could
 * reach. A laptop with APP_ENV=production is still a laptop; `localhost`, a
 * bare IP, `.test` and the documentation domains all parse and none of them
 * is on the internet.
 *
 * The integration ladder asks this before it will say "live". A real provider
 * event seen on a developer machine is real — and is not evidence about the
 * deployment customers use, which is what "live" is a claim about.
 */
final class Deployment
{
    public const LOCAL = 'local';

    public const STAGING = 'staging';

    public const PRODUCTION = 'production';

    public static function kind(): string
    {
        if (! self::reachable()) {
            return self::LOCAL;
        }

        return match (true) {
            app()->environment('production') => self::PRODUCTION,
            app()->environment('staging') => self::STAGING,
            default => self::LOCAL,
        };
    }

    public static function isProduction(): bool
    {
        return self::kind() === self::PRODUCTION;
    }

    /** HTTPS at a public name: the address a customer could actually open. */
    public static function reachable(): bool
    {
        return str_starts_with((string) config('app.url'), 'https://') && self::publicHost(self::host());
    }

    public static function host(): string
    {
        return (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    public static function publicHost(string $host): bool
    {
        return $host !== ''
            && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && preg_match('/(^|\.)(test|local|localhost|invalid|internal)$|(^|\.)example\.(com|org|net)$/i', $host) !== 1
            && str_contains($host, '.');
    }

    /** @return array{kind:string, url:string, environment:string, https:bool, public_host:bool} */
    public static function describe(): array
    {
        return [
            'kind' => self::kind(),
            'url' => (string) config('app.url'),
            'environment' => (string) app()->environment(),
            'https' => str_starts_with((string) config('app.url'), 'https://'),
            'public_host' => self::publicHost(self::host()),
        ];
    }
}
