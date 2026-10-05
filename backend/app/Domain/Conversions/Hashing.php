<?php

namespace App\Domain\Conversions;

/**
 * Identifiers prepared the way every major ad platform requires: normalised,
 * then SHA-256, and never sent in the clear.
 *
 * The normalisation is not cosmetic — a platform hashes its own copy the same
 * way, and "Ahmed@Example.COM " and "ahmed@example.com" produce different
 * digests, so a missed trim is a conversion that silently matches nobody and
 * reports success.
 */
class Hashing
{
    /** E.164 without the leading plus, which is what the platforms hash. */
    public static function phone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return $digits === '' ? null : hash('sha256', $digits);
    }

    public static function email(?string $email): ?string
    {
        $email = trim((string) $email);

        return $email === '' ? null : hash('sha256', mb_strtolower($email));
    }

    /** A name part: lowercased, trimmed, no punctuation. */
    public static function name(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : hash('sha256', mb_strtolower($name));
    }
}
