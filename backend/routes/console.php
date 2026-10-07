<?php

use Illuminate\Support\Facades\Schedule;

// Keeps the "due today / overdue" counters and notifications accurate.
Schedule::command('leadflow:follow-up-reminders')->hourly()->withoutOverlapping();

/*
 * Every five minutes, because an SLA measured in minutes cannot be enforced by
 * an hourly sweep: a ten-minute reminder that arrives fifty minutes late is not
 * a reminder. `withoutOverlapping` matters more here than elsewhere — two
 * concurrent sweeps would race on the same leads.
 */
Schedule::command('leadflow:sweep-sla')->everyFiveMinutes()->withoutOverlapping();

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
Schedule::command('leadflow:pull-leads')->everyFiveMinutes()->withoutOverlapping();

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
Schedule::command('leadflow:daily-digest')->hourly()->withoutOverlapping();

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
