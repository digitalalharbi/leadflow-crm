<?php

namespace App\Enums;

enum FollowUpOutcome: string
{
    case Replied = 'replied';
    case NoAnswer = 'no_answer';
    case Interested = 'interested';
    case NotInterested = 'not_interested';
    case Callback = 'callback';
    case Appointment = 'appointment';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
