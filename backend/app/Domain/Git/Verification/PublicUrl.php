<?php

namespace App\Domain\Git\Verification;

/**
 * Whether مهتم may make a request to this address.
 *
 * The URL being checked comes from GitHub, which means it comes from whoever
 * can create a deployment in the customer's repository. A server that will
 * fetch any URL it is handed is a server that will fetch
 * `http://169.254.169.254/latest/meta-data/` and hand the result to whoever
 * asked — which is how cloud credentials leave a company.
 *
 * So the rule is a whitelist of shapes rather than a blacklist of addresses:
 * https only, a public hostname that resolves to public addresses, a standard
 * port, no credentials in the URL. Anything unusual is refused rather than
 * reasoned about.
 */
class PublicUrl
{
    /**
     * @param  callable(string):array<int,string>|null  $resolver  the names-to-addresses
     *                                                             step, overridable so a test can exercise the address rules without
     *                                                             depending on what DNS happens to say today. Production passes none.
     * @return array{ok:bool,reason:?string}
     */
    public static function check(?string $url, ?callable $resolver = null): array
    {
        if (blank($url)) {
            return ['ok' => false, 'reason' => 'no_url'];
        }

        $parts = parse_url((string) $url);

        /*
         * https first, before anything else about the shape.
         *
         * `file:///etc/passwd` has no host to parse, and reporting it as
         * unparseable would hide the thing that is actually wrong with it from
         * whoever reads the reason.
         */
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return ['ok' => false, 'reason' => 'not_https'];
        }

        if (! isset($parts['host'])) {
            return ['ok' => false, 'reason' => 'unparseable'];
        }

        // `https://user:pass@host` would send credentials we were never given.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['ok' => false, 'reason' => 'has_credentials'];
        }

        if (isset($parts['port']) && ! in_array((int) $parts['port'], [443], true)) {
            return ['ok' => false, 'reason' => 'unusual_port'];
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if ($host === '' || ! str_contains($host, '.') || str_ends_with($host, '.localhost')) {
            return ['ok' => false, 'reason' => 'not_public'];
        }

        /*
         * Every address the name resolves to, not just the first.
         *
         * A name that answers with one public address and one loopback address
         * is the standard way round a check that looks at only one — and the
         * request would take whichever the resolver felt like.
         */
        $addresses = $resolver
            ? $resolver($host)
            : array_merge(
                gethostbynamel($host) ?: [],
                array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
            );

        if ($addresses === []) {
            return ['ok' => false, 'reason' => 'unresolvable'];
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return ['ok' => false, 'reason' => 'private_address'];
            }
        }

        return ['ok' => true, 'reason' => null];
    }
}
