<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Notifications\Concerns\MailsByPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * The rung of the SLA ladder that reaches a person.
 *
 * The sweeper wrote "تذكير آلي" and "تصعيد آلي للإدارة" into the lead's
 * timeline and sent nothing. A reminder nobody receives is not a reminder, and
 * an escalation to management that management is never told about is the
 * failure the whole ladder exists to prevent — the lead goes cold while the
 * product records, accurately, that it noticed.
 */
class SlaBreachNotification extends Notification implements ShouldQueue
{
    use MailsByPreference, Queueable;

    public const REMIND = 'remind';

    public const ESCALATE = 'escalate';

    public function __construct(
        public Lead $lead,
        /** `remind` to the owner, `escalate` to the people above them. */
        public string $stage,
        public int $ageMinutes,
    ) {}

    public function alertCategory(): ?string
    {
        return 'sla_breach';
    }

    public function alertContent(): array
    {
        $data = $this->toDatabase(null);

        return ['title' => $data['title'], 'body' => $data['body'], 'lead_id' => $this->lead->uuid, 'severity' => 'critical'];
    }

    public function toDatabase(?object $notifiable): array
    {
        $title = $this->stage === self::ESCALATE
            ? 'تصعيد: عميل بلا تواصل'
            : 'تذكير: عميل ينتظر أول تواصل';

        /*
         * The name and the number, because the point of this is that somebody
         * picks up the phone — not that they open the product, find the lead,
         * and then pick up the phone.
         */
        $body = $this->stage === self::ESCALATE
            ? "{$this->lead->full_name} مضى عليه {$this->ageMinutes} دقيقة دون تواصل من المندوب."
            : "{$this->lead->full_name} ينتظر أول تواصل منذ {$this->ageMinutes} دقيقة.";

        return [
            'type' => 'sla',
            'stage' => $this->stage,
            'lead_id' => $this->lead->uuid,
            'lead_name' => $this->lead->full_name,
            // Whatever number it has: the normalised one is still dialable, and
            // a reminder to call somebody that shows no number has lost its point.
            'lead_phone' => $this->lead->phone ?? $this->lead->phone_normalized,
            'temperature' => $this->lead->temperature?->value,
            'age_minutes' => $this->ageMinutes,
            'title' => $title,
            'body' => $body,
        ];
    }

    public function databaseType(): string
    {
        return 'sla';
    }
}
