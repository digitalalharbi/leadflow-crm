<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
    case Received = 'received';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Where this status sits in the delivery story. A provider may deliver its
     * callbacks out of order, so a "sent" arriving after a "read" must not walk
     * the message backwards.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Sent => 1,
            self::Delivered => 2,
            self::Read => 3,
            // Terminal in their own right: never overwritten by a later callback.
            self::Failed, self::Received => 99,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Failed || $this === self::Received;
    }
}
