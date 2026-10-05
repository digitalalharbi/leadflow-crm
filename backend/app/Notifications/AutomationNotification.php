<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Notifications\Channels\TenantDatabaseChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A notification a workspace's own rule asked for.
 *
 * It carries the rule's name rather than hiding behind "النظام". Somebody who
 * gets a message at 11pm should be able to see which rule sent it and go turn
 * that rule off — an automated notification whose origin is untraceable is one
 * people mute, taking the useful ones with it.
 */
class AutomationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Lead $lead,
        public string $message,
        public string $ruleName,
    ) {}

    public function via(object $notifiable): array
    {
        return [TenantDatabaseChannel::class];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'automation',
            'lead_id' => $this->lead->uuid,
            'lead_name' => $this->lead->full_name,
            'lead_phone' => $this->lead->phone,
            'temperature' => $this->lead->temperature?->value,
            'rule' => $this->ruleName,
            'title' => $this->ruleName,
            'body' => $this->message,
        ];
    }

    public function databaseType(): string
    {
        return 'automation';
    }
}
