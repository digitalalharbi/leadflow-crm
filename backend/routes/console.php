<?php

use App\Support\RoutineClock;
use Illuminate\Support\Facades\Schedule;

// Keeps the "due today / overdue" counters and notifications accurate.
Schedule::command('sanctum:prune-expired --hours=24')->daily()->withoutOverlapping();
Schedule::command('leadflow:follow-up-reminders')->hourly()->withoutOverlapping()
    ->after(fn () => RoutineClock::stamp('follow_up_reminders'));

/*
 * Every five minutes, because an SLA measured in minutes cannot be enforced by
 * an hourly sweep: a ten-minute reminder that arrives fifty minutes late is not
 * a reminder. `withoutOverlapping` matters more here than elsewhere — two
 * concurrent sweeps would race on the same leads.
 */
Schedule::command('leadflow:sweep-sla')->everyFiveMinutes()->withoutOverlapping()
    ->after(fn () => RoutineClock::stamp('sla_guardian'));

// Horizon metrics snapshot for the queue dashboard.
/*
 * Conversions go up every fifteen minutes rather than instantly. Ad platforms
 * do not optimise on the minute, and batching means a workspace that connects
 * its ad account mid-afternoon gets the whole backlog in one pass instead of a
 * retry storm.
 */
Schedule::command('leadflow:sweep-conversions')->everyFifteenMinutes()->withoutOverlapping();

/*
 * Pull-based sources, every five minutes.
 *
 * A lead that filled a form on TikTok is calling a sales team today, not
 * tomorrow, so the poll has to be closer to the SLA than to a nightly job —
 * the first-contact target is measured in minutes and a fifteen-minute poll
 * would spend most of that budget before anybody saw the lead.
 *
 * `withoutOverlapping` because a slow provider must not stack polls on top of
 * each other, each re-reading the same window.
 */
Schedule::command('leadflow:pull-leads')->everyFiveMinutes()->withoutOverlapping()
    ->after(fn () => RoutineClock::stamp('provider_pull'));

/*
 * The morning summary, hourly — and sent to each workspace at eight o'clock its
 * own time.
 *
 * It was `dailyAt('05:00')` with a comment claiming tenants had no timezone.
 * They do: `tenant_settings.timezone`, defaulting to Asia/Riyadh. One fixed UTC
 * hour is right only for workspaces that happen to share Riyadh's offset, and
 * the whole point of a morning digest is that it arrives in the morning.
 *
 * So the command runs every hour and each tenant is sent to only on the hour
 * that is 08:00 where it is. Exactly one hour a day matches.
 */
Schedule::command('leadflow:daily-digest')->hourly()->withoutOverlapping()
    ->after(fn () => RoutineClock::stamp('manager_summary'));

/*
 * The repair pass for repository activity.
 *
 * Webhooks are the primary path; this catches what they missed — a queue that
 * was down, a rotated secret, a hook GitHub disabled after too many failures.
 * Every ten minutes is frequent enough that a gap is measured in minutes, and
 * each sweep is incremental and conditional, so a quiet repository costs a
 * handful of 304s rather than any of the hourly budget.
 *
 * `withoutOverlapping` because a sweep that is still running has not finished
 * finding things, and starting a second one would double the spend to discover
 * the same changes.
 */
Schedule::command('leadflow:reconcile-repositories')
    ->everyTenMinutes()
    ->withoutOverlapping();

/*
 * Leads that arrive in a spreadsheet.
 *
 * Every minute, incremental from each sheet's own cursor — two small reads
 * per sheet, well inside Google's quota. A sheet with its Apps Script ping
 * installed is synced within seconds of a row arriving; this sweep is the
 * floor for one without it. Speed-to-lead is the product, so a lead that sat
 * in a sheet for fifteen minutes before anybody wrote to it was a defect.
 */
Schedule::command('leadflow:sync-sheets')
    ->everyMinute()
    ->withoutOverlapping()
    ->after(fn () => RoutineClock::stamp('new_lead_watch'));

/*
 * Ad accounts into the campaign tables, and leads onto their campaigns, is the
 * agents' `hourly_sync` routine: hourly, because platforms report daily and more
 * often buys nothing but API quota. `leadflow:sync-campaigns` remains for a
 * manual run.
 */
// The agents' own schedule (daily analysis, budget guard, …), in each
// workspace's timezone. The runner decides what is due; this only wakes it.
/*
 * "No reply" as a moment rules can act on: quiet 15 minutes after our last
 * message, an hour, four hours, a day… Every five minutes, so "15 min" means
 * fifteen to twenty.
 */
// Interest decays with silence and no event says so: re-read what has gone stale, hourly.
Schedule::command('leadflow:refresh-interest')->hourly()->withoutOverlapping();

// The email summaries: eight o'clock where each workspace is; sales daily, marketing and executive on Sundays.
Schedule::command('leadflow:send-digests')->hourly()->withoutOverlapping();

// Numbers outside their normal range (leads, cost, qualified rate, tracking, backlog): once a day each, to the managers.
Schedule::command('leadflow:detect-anomalies')->hourly()->withoutOverlapping();

// A connected platform that stopped delivering or stopped being told about sales: tell the managers.
Schedule::command('leadflow:watch-tracking')->hourly()->withoutOverlapping();

// A hold nobody confirmed lapses, and the unit goes back on the market.
Schedule::command('leadflow:expire-reservations')->hourly()->withoutOverlapping();
Schedule::command('leadflow:sweep-reminders')->everyTenMinutes()->withoutOverlapping();

Schedule::command('leadflow:sweep-no-reply')->everyFiveMinutes()->withoutOverlapping()
    ->after(fn () => RoutineClock::stamp('no_reply_review'));

Schedule::command('leadflow:run-routines')->everyFiveMinutes()->withoutOverlapping();

// A wait whose timer was lost (a flushed queue, a worker that died) is found and run, not forgotten.
Schedule::command('leadflow:sweep-scheduled')->everyTenMinutes()->withoutOverlapping();

Schedule::command('horizon:snapshot')->everyFiveMinutes();

/*
 * Raw inbound payloads, forgotten on a schedule.
 *
 * Nothing pruned them, so every webhook body and every customer's WhatsApp
 * message was kept for the life of the workspace against a debugging question
 * that is asked within days of a lead arriving. Nightly and off-peak: it is a
 * large delete on tables nothing is waiting on.
 */
Schedule::command('leadflow:prune-payloads')->dailyAt('03:30')->withoutOverlapping();

// Export files live a day; stuck exports are failed so the screen stops waiting.
Schedule::command('leadflow:prune-exports')->hourly()->withoutOverlapping();

/*
 * The pulse the production-readiness screen reads.
 *
 * Every minute, and cheap: a cache write and one queued no-op. Without it the
 * application cannot tell "the scheduler is configured" from "the scheduler is
 * running", and only the second is worth the word live.
 */
Schedule::command('leadflow:heartbeat')->everyMinute();

// Reports that send themselves: each workspace's daily, weekly and monthly deliveries, at their own hour.
Schedule::command('leadflow:send-report-schedules')->hourly()->withoutOverlapping();
