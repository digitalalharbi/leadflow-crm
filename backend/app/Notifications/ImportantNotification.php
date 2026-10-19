<?php

namespace App\Notifications;

use App\Notifications\Channels\TenantDatabaseChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * One of the few things worth interrupting somebody for: a hot lead waiting, a
 * missed SLA, an automation that failed, an appointment asked for, a very
 * valuable lead, a campaign that got worse. Carries only primitives so it
 * survives the queue without a tenant context.
 */
class ImportantNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $kind,
        public string $title,
        public string $body,
        public ?string $leadId = null,
        public ?string $link = null,
        public string $severity = 'warning',
    ) {}

    public function via(object $notifiable): array
    {
        return [TenantDatabaseChannel::class];
    }

    public function toDatabase(object $notifiable): array
    {
        return array_filter([
            'type' => 'important',
            'kind' => $this->kind,
            'severity' => $this->severity,
            'title' => $this->title,
            'body' => $this->body,
            'lead_id' => $this->leadId,
            'link' => $this->link,
        ], fn ($value) => $value !== null);
    }

    public function databaseType(): string
    {
        return 'important';
    }
}
