<?php

namespace App\Domain\Sla;

use App\Models\TenantSetting;
use Illuminate\Support\Carbon;

/**
 * How many minutes of the working week passed between two moments.
 *
 * The response-time clock counted wall-clock minutes, so a lead arriving at two
 * in the morning was reminded at ten past, escalated to the management at half
 * past, and taken off its rep by three — for a call nobody could have made. The
 * rep opened their laptop to a breach they could not have avoided, which is the
 * fastest way to teach a team that the SLA is noise.
 *
 * Unconfigured means always open. Every workspace starts there and stays there
 * until somebody says when they work, so this changes nothing until it is asked
 * to.
 */
class BusinessHours
{
    /**
     * @param  array<int,int>|null  $days  ISO weekdays, 1 = Monday … 7 = Sunday
     */
    private function __construct(
        private readonly ?array $days,
        private readonly ?string $startsAt,
        private readonly ?string $endsAt,
        private readonly string $timezone,
    ) {}

    public static function for(?TenantSetting $settings): self
    {
        return new self(
            $settings?->work_days ?: null,
            $settings?->work_starts_at,
            $settings?->work_ends_at,
            $settings?->timezone ?: config('app.timezone'),
        );
    }

    /** Nothing has been said about when this workspace works. */
    public function alwaysOpen(): bool
    {
        return $this->days === null || $this->startsAt === null || $this->endsAt === null;
    }

    public function isOpenAt(Carbon $moment): bool
    {
        if ($this->alwaysOpen()) {
            return true;
        }

        $local = $moment->copy()->setTimezone($this->timezone);

        if (! in_array($local->dayOfWeekIso, $this->days, true)) {
            return false;
        }

        $minute = $local->hour * 60 + $local->minute;

        return $minute >= $this->minuteOf($this->startsAt) && $minute < $this->minuteOf($this->endsAt);
    }

    /**
     * Working minutes between two moments.
     *
     * Walks day by day in the workspace's own timezone rather than doing
     * arithmetic on offsets: the alternative gets a workspace wrong twice a
     * year, and the bound below keeps a lead that has been sitting since March
     * from walking a hundred days to find out it is very late indeed.
     */
    public function minutesBetween(Carbon $from, Carbon $to): int
    {
        if ($this->alwaysOpen()) {
            return max(0, (int) $from->diffInMinutes($to));
        }

        if ($to->lessThanOrEqualTo($from)) {
            return 0;
        }

        $start = $from->copy()->setTimezone($this->timezone);
        $end = $to->copy()->setTimezone($this->timezone);

        $minutes = 0;
        $day = $start->copy()->startOfDay();

        // Sixty days of working time is far past any SLA rung; anything older is
        // already as late as it can usefully be described as being.
        for ($i = 0; $i < 60 && $day->lessThanOrEqualTo($end); $i++, $day->addDay()) {
            if (! in_array($day->dayOfWeekIso, $this->days, true)) {
                continue;
            }

            $opens = $day->copy()->setTime(0, 0)->addMinutes($this->minuteOf($this->startsAt));
            $closes = $day->copy()->setTime(0, 0)->addMinutes($this->minuteOf($this->endsAt));

            $windowStart = $start->greaterThan($opens) ? $start : $opens;
            $windowEnd = $end->lessThan($closes) ? $end : $closes;

            if ($windowEnd->greaterThan($windowStart)) {
                $minutes += (int) $windowStart->diffInMinutes($windowEnd);
            }
        }

        return $minutes;
    }

    private function minuteOf(string $time): int
    {
        [$hour, $minute] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        return $hour * 60 + $minute;
    }
}
