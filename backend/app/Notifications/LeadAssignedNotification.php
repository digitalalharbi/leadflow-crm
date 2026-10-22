<?php

namespace App\Notifications;

use App\Domain\Notifications\ImportantAlerts;
use App\Models\Lead;
use App\Notifications\Concerns\MailsByPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class LeadAssignedNotification extends Notification implements ShouldQueue
{
    use MailsByPreference, Queueable;

    public function __construct(public Lead $lead) {}

    /** "A new lead" is a category a person can choose to be emailed about; in the app it is always there. */
    public function alertCategory(): ?string
    {
        return 'new_lead';
    }

    public function alertContent(): array
    {
        return ['title' => 'عميل محتمل جديد', 'body' => $this->body(), 'lead_id' => $this->lead->uuid, 'severity' => 'warning'];
    }

    private function body(): string
    {
        return "تم إسناد {$this->lead->full_name} إليك".ImportantAlerts::about($this->lead).'.';
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'lead_assigned',
            'kind' => 'new_lead',
            'lead_id' => $this->lead->uuid,
            'lead_name' => $this->lead->full_name,
            'lead_phone' => $this->lead->phone,
            'temperature' => $this->lead->temperature?->value,
            'title' => 'عميل محتمل جديد',
            'body' => $this->body(),
        ];
    }

    public function databaseType(): string
    {
        return 'lead_assigned';
    }
}
