<?php

namespace App\Notifications;

use App\Models\FollowUp;
use App\Notifications\Channels\TenantDatabaseChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class FollowUpDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public FollowUp $followUp, public bool $overdue = false) {}

    public function via(object $notifiable): array
    {
        return [TenantDatabaseChannel::class];
    }

    public function toDatabase(object $notifiable): array
    {
        $lead = $this->followUp->lead;

        return [
            'type' => $this->overdue ? 'follow_up_overdue' : 'follow_up_due',
            'follow_up_id' => $this->followUp->uuid,
            'lead_id' => $lead?->uuid,
            'lead_name' => $lead?->full_name,
            'due_at' => $this->followUp->due_at?->toIso8601String(),
            'title' => $this->overdue ? 'متابعة متأخرة' : 'متابعة مستحقة اليوم',
            'body' => "متابعة {$lead?->full_name}",
        ];
    }

    public function databaseType(): string
    {
        return $this->overdue ? 'follow_up_overdue' : 'follow_up_due';
    }
}
