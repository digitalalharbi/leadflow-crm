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
 * The morning summary, at 05:00 UTC — 08:00 in Riyadh, which is the start of
 * the working day for every workspace this product has.
 *
 * Tenants carry no timezone of their own yet; when they do, this becomes a
 * per-tenant send rather than one hour for everybody.
 */
Schedule::command('leadflow:daily-digest')->dailyAt('05:00')->withoutOverlapping();

Schedule::command('horizon:snapshot')->everyFiveMinutes();
