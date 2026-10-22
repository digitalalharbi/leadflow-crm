<?php

namespace App\Domain\Conversions;

use App\Models\AdConnection;
use App\Models\Integration;

/**
 * The credentials a conversion upload needs, from wherever the workspace gave
 * them: the lead-source integration first (where they always lived), then the
 * ad connection's credentials and settings. Never logged, never returned.
 */
final class ConversionCredentials
{
    public static function for(string $platform): ?array
    {
        $integration = Integration::query()->where('provider', $platform)->where('is_active', true)->first();
        $connection = AdConnection::query()->where('platform', $platform)->where('is_active', true)->first();

        $merged = array_merge(
            $connection ? array_merge((array) ($connection->config ?? []), (array) ($connection->credentials ?? [])) : [],
            (array) ($integration?->credentials ?? []),
        );

        return $merged === [] ? null : $merged;
    }
}
