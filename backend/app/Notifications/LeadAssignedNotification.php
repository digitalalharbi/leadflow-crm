<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Notifications\Channels\TenantDatabaseChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class LeadAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Lead $lead) {}

    public function via(object $notifiable): array
    {
        return [TenantDatabaseChannel::class];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'lead_assigned',
            'lead_id' => $this->lead->uuid,
            'lead_name' => $this->lead->full_name,
            'lead_phone' => $this->lead->phone,
            'temperature' => $this->lead->temperature?->value,
            'title' => 'عميل محتمل جديد',
            'body' => "تم إسناد {$this->lead->full_name} إليك.",
        ];
    }

    public function databaseType(): string
    {
        return 'lead_assigned';
    }
}
