<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Public identifiers are UUIDs in Postgres `uuid` columns, so a value that is
 * not one never reaches the driver — it would raise SQLSTATE 22P02, which is a
 * 500 any caller could trigger with a typo. Filters drop malformed values and
 * therefore match nothing, which is the honest answer to "leads in project ?".
 */
class Uuid
{
    public static function isValid(mixed $value): bool
    {
        return is_string($value) && Str::isUuid($value);
    }

    /**
     * @return array<int, string> the well-formed identifiers, re-indexed
     */
    public static function onlyValid(mixed $values): array
    {
        return array_values(array_filter((array) $values, self::isValid(...)));
    }

    /** A single identifier, or null when it could not be one. */
    public static function orNull(mixed $value): ?string
    {
        return self::isValid($value) ? $value : null;
    }
}
