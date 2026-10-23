<?php

namespace App\Domain\Tracking;

/**
 * The identifiers an ad platform puts on a click so a sale can be credited back to it.
 *
 * Read from a query string or a form post, kept only when they look like what they are — a short
 * scalar — and never anything else: the list is closed, so a page cannot smuggle a name, a phone
 * number or an email in under a parameter called "gclid2".
 */
class ClickIds
{
    /** Google (click, app-to-web, web-to-app), Meta, TikTok, Snapchat, Microsoft, LinkedIn, X. */
    public const KEYS = ['gclid', 'gbraid', 'wbraid', 'fbclid', 'ttclid', 'sccid', 'msclkid', 'li_fat_id', 'twclid'];

    private const MAX_LENGTH = 512;

    /**
     * @param  array<string,mixed>  $source
     * @return array<string,string> only the click ids present and well-formed, in the list's order
     */
    public static function from(array $source): array
    {
        $found = [];

        foreach (self::KEYS as $key) {
            $value = $source[$key] ?? null;

            if (is_string($value) && $value !== '' && mb_strlen($value) <= self::MAX_LENGTH) {
                $found[$key] = $value;
            }
        }

        return $found;
    }

    /** The single value the older `click_id` column holds: the first id there is, in the list's order. */
    public static function primary(array $clickIds): ?string
    {
        return $clickIds === [] ? null : array_values($clickIds)[0];
    }
}
