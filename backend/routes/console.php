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
Schedule::command('horizon:snapshot')->everyFiveMinutes();
