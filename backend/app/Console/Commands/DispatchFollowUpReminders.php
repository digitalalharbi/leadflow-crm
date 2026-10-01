<?php

namespace App\Console\Commands;

use App\Models\FollowUp;
use App\Models\Tenant;
use App\Notifications\FollowUpDueNotification;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Scheduled hourly. Notifies reps about follow-ups due today and ones that have
 * slipped, which is what keeps the "overdue" number on the dashboard honest.
 */
class DispatchFollowUpReminders extends Command
{
    protected $signature = 'leadflow:follow-up-reminders';

    protected $description = 'Notify sales reps about due and overdue follow-ups';

    public function handle(TenantContext $context): int
    {
        $tenants = $context->withoutScope(fn () => Tenant::where('is_active', true)->get());
        $sent = 0;

        foreach ($tenants as $tenant) {
            $context->withTenant($tenant, function () use (&$sent) {
                $due = FollowUp::query()->dueToday()->with('lead', 'assignee')->get();
                $overdue = FollowUp::query()->overdue()->with('lead', 'assignee')->get();

                foreach ($due as $followUp) {
                    $followUp->assignee?->notify(new FollowUpDueNotification($followUp, overdue: false));
                    $sent++;
                }

                foreach ($overdue as $followUp) {
                    $followUp->assignee?->notify(new FollowUpDueNotification($followUp, overdue: true));
                    $sent++;
                }
            });
        }

        $this->info("Dispatched {$sent} follow-up reminders.");

        return self::SUCCESS;
    }
}
