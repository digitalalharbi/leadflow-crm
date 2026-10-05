<?php

namespace App\Support;

/**
 * A span of minutes, in Arabic a person would actually say.
 *
 * "متأخر 4321 دقيقة" is technically true and nobody can read it. A rep glancing
 * at a card needs to know the follow-up is three days late, not to divide by
 * 1440 in their head while the phone rings. Arabic also counts in six
 * categories rather than two, so the unit word changes with the number:
 * دقيقة / دقيقتان / دقائق / دقيقة again past ten.
 */
class ArabicDuration
{
    /** Rounded to the largest unit that still says something useful. */
    public static function fromMinutes(int $minutes): string
    {
        $minutes = abs($minutes);

        if ($minutes < 60) {
            return self::count($minutes, 'دقيقة', 'دقيقتين', 'دقائق', 'دقيقة');
        }

        if ($minutes < 60 * 24) {
            return self::count(intdiv($minutes, 60), 'ساعة', 'ساعتين', 'ساعات', 'ساعة');
        }

        return self::count(intdiv($minutes, 60 * 24), 'يوم', 'يومين', 'أيام', 'يومًا');
    }

    /**
     * The five forms Arabic needs for a counted noun.
     *
     * 1 and 2 have their own words and take no numeral; 3–10 take the plural;
     * 11 and above return to the singular in the accusative.
     *
     * The dual is given in its oblique form — ساعتين, not ساعتان — because
     * every sentence in the product puts it after a preposition or a verb,
     * where the nominative is simply wrong. "متأخرة بـ ساعتان" appeared on the
     * work screen and reads to an Arabic speaker the way "overdue by two hour"
     * reads in English.
     */
    private static function count(int $n, string $one, string $two, string $few, string $many): string
    {
        return match (true) {
            $n === 0 => 'أقل من دقيقة',
            $n === 1 => $one,
            $n === 2 => $two,
            $n <= 10 => "{$n} {$few}",
            default => "{$n} {$many}",
        };
    }
}
