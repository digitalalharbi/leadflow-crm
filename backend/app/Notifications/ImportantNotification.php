<?php

namespace App\Notifications;

use App\Domain\Notifications\AlertKinds;
use App\Notifications\Concerns\MailsByPreference;
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
    use MailsByPreference, Queueable;

    public function __construct(
        public string $kind,
        public string $title,
        public string $body,
        public ?string $leadId = null,
        public ?string $link = null,
        public string $severity = 'warning',
    ) {}

    public function alertCategory(): ?string
    {
        return AlertKinds::categoryOf($this->kind);
    }

    public function alertContent(): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'lead_id' => $this->leadId, 'severity' => $this->severity];
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
