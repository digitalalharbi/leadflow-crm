<?php

namespace App\Notifications;

use App\Notifications\Channels\TenantDatabaseChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Something an agent found that a manager should see now, with where to look. */
class AgentAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $agent, public string $title, public string $body, public string $link) {}

    public function via(object $notifiable): array
    {
        return [TenantDatabaseChannel::class];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['type' => 'agent', 'agent' => $this->agent, 'title' => $this->title, 'body' => $this->body, 'link' => $this->link];
    }

    public function databaseType(): string
    {
        return 'agent';
    }
}
