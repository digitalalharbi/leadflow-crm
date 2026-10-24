<?php

namespace App\Domain\Reports;

use App\Models\ReportShare;
use Carbon\CarbonImmutable;

/**
 * The period a report covers and the one it is compared with.
 *
 * Today, yesterday, the last 7 / 14 / 30 days, this month, the previous month, the quarter so far, or a range chosen by
 * date. A report made before this existed has only a number of days: that is "the last N days", exactly as it was.
 * The comparison is the period just before it (the same length), the same dates one month earlier, or a range of its own.
 */
final class ReportPeriod
{
    /** @return array{from: CarbonImmutable, to: CarbonImmutable} */
    public static function resolve(?string $range, int $days, ?string $from = null, ?string $to = null, ?CarbonImmutable $now = null): array
    {
        $today = ($now ?? CarbonImmutable::now())->startOfDay();
        $end = fn (CarbonImmutable $d) => $d->endOfDay();

        return match ($range) {
            'today' => ['from' => $today, 'to' => $end($today)],
            'yesterday' => ['from' => $today->subDay(), 'to' => $end($today->subDay())],
            'last_7' => ['from' => $today->subDays(6), 'to' => $end($today)],
            'last_14' => ['from' => $today->subDays(13), 'to' => $end($today)],
            'last_30' => ['from' => $today->subDays(29), 'to' => $end($today)],
            'this_month' => ['from' => $today->startOfMonth(), 'to' => $end($today)],
            'previous_month' => ['from' => $today->subMonthNoOverflow()->startOfMonth(), 'to' => $end($today->subMonthNoOverflow()->endOfMonth())],
            'quarter' => ['from' => $today->startOfQuarter(), 'to' => $end($today)],
            'custom' => self::custom($from, $to, $today),
            default => ['from' => $today->subDays(max(1, $days) - 1), 'to' => $end($today)],
        };
    }

    /** @return array{from: CarbonImmutable, to: CarbonImmutable} */
    private static function custom(?string $from, ?string $to, CarbonImmutable $today): array
    {
        $a = $from ? CarbonImmutable::parse($from)->startOfDay() : $today->subDays(29);
        $b = $to ? CarbonImmutable::parse($to)->endOfDay() : $today->endOfDay();

        return $a->lte($b) ? ['from' => $a, 'to' => $b] : ['from' => $b->startOfDay(), 'to' => $a->endOfDay()];
    }

    /** @return array{from: CarbonImmutable, to: CarbonImmutable} */
    public static function comparison(CarbonImmutable $from, CarbonImmutable $to, string $mode = 'previous_period', ?string $customFrom = null, ?string $customTo = null): array
    {
        if ($mode === 'custom' && $customFrom && $customTo) {
            return self::custom($customFrom, $customTo, $from);
        }

        if ($mode === 'previous_month') {
            return ['from' => $from->subMonthNoOverflow()->startOfDay(), 'to' => $to->subMonthNoOverflow()->endOfDay()];
        }

        $days = max(1, (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1);
        $prevTo = $from->subDay()->endOfDay();

        return ['from' => $prevTo->subDays($days - 1)->startOfDay(), 'to' => $prevTo];
    }

    /** @return array{from: CarbonImmutable, to: CarbonImmutable, days: int, prev_from: CarbonImmutable, prev_to: CarbonImmutable} */
    public static function window(ReportShare $share, ?int $sectionDays = null, ?CarbonImmutable $now = null): array
    {
        $main = $sectionDays !== null
            ? self::resolve(null, $sectionDays, now: $now)
            : self::resolve($share->period, (int) $share->days, $share->period_from?->toDateString(), $share->period_to?->toDateString(), $now);

        $prev = self::comparison($main['from'], $main['to'], (string) ($share->compare_mode ?: 'previous_period'), $share->compare_from?->toDateString(), $share->compare_to?->toDateString());

        return [
            'from' => $main['from'], 'to' => $main['to'],
            'days' => max(1, (int) $main['from']->startOfDay()->diffInDays($main['to']->startOfDay()) + 1),
            'prev_from' => $prev['from'], 'prev_to' => $prev['to'],
        ];
    }
}
