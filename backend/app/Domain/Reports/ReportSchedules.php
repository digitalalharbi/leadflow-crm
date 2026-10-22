<?php

namespace App\Domain\Reports;

use App\Domain\Mail\EmailDeliveries;
use App\Domain\Mail\OutboundEmail;
use App\Domain\Mail\ReportEmail;
use App\Models\ReportSchedule;
use App\Models\TenantSetting;
use Carbon\CarbonImmutable;

/**
 * Reports that send themselves.
 *
 * A schedule says how often (daily, weekly on a weekday, monthly on a day up to the 28th), at which
 * hour of the workspace's own clock, to whom. When it comes due each recipient is queued one email —
 * the link, never the numbers, exactly as when a person sends it by hand — through the same door,
 * with the same retries and the same delivery log. A person is never sent the same report twice for
 * the same run: the idempotency key carries the day. A schedule whose report has been revoked or has
 * expired stops, and says so, instead of mailing a link that no longer opens.
 */
class ReportSchedules
{
    public function __construct(private EmailDeliveries $deliveries) {}

    /** The first run strictly after `$after`, in the workspace's timezone. */
    public function nextRun(ReportSchedule $schedule, ?CarbonImmutable $after = null): CarbonImmutable
    {
        $tz = (string) (TenantSetting::query()->where('tenant_id', $schedule->tenant_id)->value('timezone') ?: 'Asia/Riyadh');
        $from = ($after ?? CarbonImmutable::now())->setTimezone($tz);
        $hour = (int) $schedule->send_hour;
        $at = $from->setTime($hour, 0);

        $next = match ($schedule->frequency) {
            'weekly' => $this->weekly($at, $from, max(1, min(7, (int) ($schedule->weekday ?? 1)))),
            'monthly' => $this->monthly($at, $from, max(1, min(28, (int) ($schedule->month_day ?? 1)))),
            default => $at->lte($from) ? $at->addDay() : $at,
        };

        return $next->setTimezone('UTC');
    }

    private function weekly(CarbonImmutable $at, CarbonImmutable $from, int $weekday): CarbonImmutable
    {
        $candidate = $at->addDays(($weekday - $at->dayOfWeekIso + 7) % 7);

        return $candidate->lte($from) ? $candidate->addWeek() : $candidate;
    }

    private function monthly(CarbonImmutable $at, CarbonImmutable $from, int $day): CarbonImmutable
    {
        $candidate = $at->setDay($day);

        return $candidate->lte($from) ? $at->addMonthNoOverflow()->setDay($day) : $candidate;
    }

    /** Fixes when a schedule next runs — on creation, and whenever its timing is edited. */
    public function arm(ReportSchedule $schedule): ReportSchedule
    {
        $schedule->forceFill(['next_run_at' => $schedule->enabled ? $this->nextRun($schedule) : null])->save();

        return $schedule;
    }

    /** @return int how many schedules ran */
    public function runDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $ran = 0;

        ReportSchedule::query()->where('enabled', true)->whereNotNull('next_run_at')->where('next_run_at', '<=', $now)->with('share')->get()
            ->each(function (ReportSchedule $schedule) use ($now, &$ran) {
                $this->run($schedule, $now);
                $ran++;
            });

        return $ran;
    }

    /** One run: queue an email per recipient, or stop if the report is closed. @return int emails queued */
    public function run(ReportSchedule $schedule, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $schedule->loadMissing('creator');
        $share = $schedule->share()->with(['tenant.branding', 'tenant.settings'])->first();

        if ($share === null || $share->status() !== 'active') {
            // A closed report is not mailed: the schedule stops until somebody turns it back on.
            $schedule->forceFill(['enabled' => false, 'next_run_at' => null, 'last_status' => 'stopped', 'last_run_at' => $now])->save();

            return 0;
        }

        $tz = (string) ($share->tenant?->settings?->timezone ?: 'Asia/Riyadh');
        $day = $now->setTimezone($tz)->toDateString();
        $queued = 0;

        foreach ((array) $schedule->recipients as $address) {
            $mail = ReportEmail::compose($share, (string) $address, $schedule->message, $schedule->creator);

            $this->deliveries->queue(
                new OutboundEmail((string) $address, null, $mail['subject'], $mail['html'], null, '', '', hash('sha256', "schedule|{$schedule->id}|{$address}|{$day}"), ['kind' => 'report', 'schedule' => $schedule->frequency]),
                ['kind' => 'report', 'subject_type' => 'report_schedule', 'subject_id' => $schedule->id],
                $schedule->creator,
            );
            $queued++;
        }

        $schedule->forceFill(['last_run_at' => $now, 'last_status' => 'sent', 'next_run_at' => $this->nextRun($schedule, $now)])->save();

        return $queued;
    }
}
