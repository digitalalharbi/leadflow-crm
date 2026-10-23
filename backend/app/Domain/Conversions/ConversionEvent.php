<?php

namespace App\Domain\Conversions;

/**
 * The sales outcomes worth telling an ad platform about.
 *
 * Deliberately short. Every event uploaded is a signal the platform optimises
 * towards, and a workspace that uploads six of them has told the optimiser
 * nothing about which one it actually wants more of.
 */
enum ConversionEvent: string
{
    case Qualified = 'qualified';
    case Appointment = 'appointment';
    case Won = 'won';
    // The later signals a campaign can be optimised on: not just a lead, but one who is interested, and one who reserved.
    case Interested = 'interested';
    case Reservation = 'reservation';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** What this event is called in the platform's own vocabulary. */
    public function standardName(): string
    {
        return match ($this) {
            self::Qualified => 'Lead',
            self::Appointment => 'Schedule',
            self::Won => 'Purchase',
            self::Interested => 'Contact',
            self::Reservation => 'InitiateCheckout',
        };
    }
}
