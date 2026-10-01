<?php

namespace App\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Appointment = 'appointment';
    case Won = 'won';
    case Lost = 'lost';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Pipeline order used by the Kanban board and reports. */
    public function position(): int
    {
        return match ($this) {
            self::New => 1,
            self::Contacted => 2,
            self::Qualified => 3,
            self::Appointment => 4,
            self::Won => 5,
            self::Lost => 6,
        };
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::Won, self::Lost], true);
    }
}
