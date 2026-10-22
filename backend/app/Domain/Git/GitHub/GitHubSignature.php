<?php

namespace App\Domain\Git\GitHub;

/**
 * Whether a delivery really came from GitHub.
 *
 * The only thing standing between a public URL and anybody being able to write
 * a merge into a customer's timeline. Three rules, all of which have been got
 * wrong in public postmortems:
 *
 *  - compare the **raw body**, not a re-encoded array: `json_decode` then
 *    `json_encode` reorders keys and changes escaping, so the hash stops
 *    matching for reasons that look random;
 *  - compare in **constant time**, because a byte-by-byte comparison leaks the
 *    expected digest to anybody willing to send a few thousand requests;
 *  - a missing signature is a **failure**, never a pass. The tempting shape is
 *    "verify if a secret is configured", and that is an open door with a lock
 *    hanging on it.
 */
class GitHubSignature
{
    public static function matches(string $rawBody, ?string $header, ?string $secret): bool
    {
        if (blank($header) || blank($secret)) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $header);
    }
}
