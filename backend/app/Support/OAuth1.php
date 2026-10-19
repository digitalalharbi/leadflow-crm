<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * OAuth 1.0a request signing (HMAC-SHA1), as X's Ads API requires.
 *
 * The signature covers the method, the URL without its query, and every query
 * and form parameter together with the oauth_* ones, each percent-encoded per
 * RFC 3986 and sorted. Get any of that wrong and X answers 401 with nothing
 * else to go on, which is why it lives here, tested, and not inline.
 */
final class OAuth1
{
    /** @param  array<string, scalar>  $params  query and form parameters of the request */
    public static function header(
        string $method,
        string $url,
        array $params,
        string $consumerKey,
        string $consumerSecret,
        string $token,
        string $tokenSecret,
        ?string $nonce = null,
        ?int $timestamp = null,
    ): string {
        $oauth = [
            'oauth_consumer_key' => $consumerKey,
            'oauth_nonce' => $nonce ?? Str::random(32),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string) ($timestamp ?? time()),
            'oauth_token' => $token,
            'oauth_version' => '1.0',
        ];

        $oauth['oauth_signature'] = self::signature($method, $url, array_merge($params, $oauth), $consumerSecret, $tokenSecret);

        ksort($oauth);

        return 'OAuth '.implode(', ', array_map(
            fn ($k, $v) => self::encode($k).'="'.self::encode($v).'"',
            array_keys($oauth),
            $oauth,
        ));
    }

    public static function signature(string $method, string $url, array $params, string $consumerSecret, string $tokenSecret): string
    {
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = [self::encode((string) $k), self::encode((string) $v)];
        }
        sort($pairs);

        $normalised = implode('&', array_map(fn ($p) => $p[0].'='.$p[1], $pairs));
        $base = strtoupper($method).'&'.self::encode(strtok($url, '?')).'&'.self::encode($normalised);

        return base64_encode(hash_hmac('sha1', $base, self::encode($consumerSecret).'&'.self::encode($tokenSecret), true));
    }

    public static function encode(string $value): string
    {
        return rawurlencode($value);
    }
}
