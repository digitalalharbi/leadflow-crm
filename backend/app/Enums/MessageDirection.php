<?php

namespace App\Enums;

enum MessageDirection: string
{
    case Outbound = 'outbound';
    case Inbound = 'inbound';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
