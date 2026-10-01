<?php

namespace App\Enums;

enum LeadTemperature: string
{
    case Hot = 'hot';
    case Warm = 'warm';
    case Cold = 'cold';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Default score mapping; thresholds are per-tenant settings. */
    public static function fromScore(int $score, int $warmAt = 40, int $hotAt = 70): self
    {
        return match (true) {
            $score >= $hotAt => self::Hot,
            $score >= $warmAt => self::Warm,
            default => self::Cold,
        };
    }
}
