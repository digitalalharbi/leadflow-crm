<?php

namespace App\Domain\Calendar;

use App\Models\Appointment;
use Illuminate\Support\Carbon;

/**
 * Appointments as iCalendar (RFC 5545): what Google, Apple and Outlook calendars read from a file or a subscription.
 *
 * Times are written in UTC so every calendar places them right whatever its own zone. Text is escaped and lines are
 * folded at 75 octets as the format requires; Arabic survives because folding never splits a UTF-8 character.
 * A visit carries the lead's name, the project and the place — never the phone number: a feed address can be
 * forwarded, and a calendar is not where a customer's number should live.
 */
class IcsCalendar
{
    /** @param iterable<Appointment> $appointments */
    public static function render(iterable $appointments, string $name, string $appUrl): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Mohtam//Visits//AR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::text($name),
            'X-PUBLISHED-TTL:PT1H',
        ];

        foreach ($appointments as $appointment) {
            if ($appointment->scheduled_at === null) {
                continue;
            }

            $start = Carbon::parse($appointment->scheduled_at)->utc();
            $end = $start->copy()->addMinutes(max(5, (int) ($appointment->duration_minutes ?: 60)));
            $lead = $appointment->lead;
            $title = trim('زيارة — '.($lead?->full_name ?? ''), ' —');
            $link = rtrim($appUrl, '/').($lead ? '/leads/'.$lead->uuid : '/visits');

            $lines = [...$lines,
                'BEGIN:VEVENT',
                'UID:'.$appointment->uuid.'@mohtam',
                'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
                'DTSTART:'.$start->format('Ymd\THis\Z'),
                'DTEND:'.$end->format('Ymd\THis\Z'),
                'SUMMARY:'.self::text($title),
                'DESCRIPTION:'.self::text(trim(($appointment->project?->name ? $appointment->project->name."\n" : '').$link)),
                ...($appointment->location ? ['LOCATION:'.self::text($appointment->location)] : []),
                'URL:'.$link,
                'STATUS:'.(in_array($appointment->status, ['cancelled', 'no_show'], true) ? 'CANCELLED' : 'CONFIRMED'),
                'END:VEVENT',
            ];
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $lines))."\r\n";
    }

    /** Escapes a TEXT value: backslash, semicolon, comma and newline. */
    public static function text(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\\,', '\\n'], $value);
    }

    /** Folds a content line at 75 octets without splitting a multi-byte character. */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = '';
        $current = '';

        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > ($out === '' ? 75 : 74)) {
                $out .= ($out === '' ? '' : "\r\n ").$current;
                $current = '';
            }
            $current .= $char;
        }

        return $out.($out === '' ? '' : "\r\n ").$current;
    }
}
