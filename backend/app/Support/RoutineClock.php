<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * When each system routine last finished, so the Routines screen can say
 * "ran 3 minutes ago" rather than describe a schedule nobody can see running.
 * Stamped by the scheduler after each run; absent means it has not run here.
 */
class RoutineClock
{
    /** key => [command, cadence in words the screen translates] */
    public const SYSTEM = [
        'new_lead_watch' => ['leadflow:sync-sheets', 'continuous'],
        'provider_pull' => ['leadflow:pull-leads', 'every_5_minutes'],
        'sla_guardian' => ['leadflow:sweep-sla', 'every_5_minutes'],
        'no_reply_review' => ['leadflow:sweep-no-reply', 'every_5_minutes'],
        'follow_up_reminders' => ['leadflow:follow-up-reminders', 'hourly'],
        'manager_summary' => ['leadflow:daily-digest', 'daily'],
    ];

    public static function stamp(string $key): void
    {
        Cache::forever("routine-ran:{$key}", now()->toIso8601String());
    }

    public static function last(string $key): ?string
    {
        return Cache::get("routine-ran:{$key}");
    }
}
