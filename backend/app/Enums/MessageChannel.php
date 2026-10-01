<?php

namespace App\Enums;

/**
 * Only WhatsApp is implemented. SMS and email are listed because the message
 * table is channel-agnostic by design — adding one later is a provider, not a
 * schema change.
 */
enum MessageChannel: string
{
    case Whatsapp = 'whatsapp';
    case Sms = 'sms';
    case Email = 'email';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
