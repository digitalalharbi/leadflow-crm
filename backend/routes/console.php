<?php

use Illuminate\Support\Facades\Schedule;

// Keeps the "due today / overdue" counters and notifications accurate.
Schedule::command('leadflow:follow-up-reminders')->hourly()->withoutOverlapping();

// Horizon metrics snapshot for the queue dashboard.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
