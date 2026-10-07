<?php

namespace App\Notifications;

use App\Enums\Platform;
use App\Notifications\Channels\TenantDatabaseChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * The morning summary, in the notification tray rather than by email.
 *
 * Mail is not configured in this product — `MAIL_MAILER=log` — and wiring a
 * transport means SMTP credentials that only the customer can supply. The
 * in-app channel every other notification already uses needs nothing, works
 * today, and is where somebody opening مهتم will actually look.
 */
class DailyDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<string, mixed> $digest */
    public function __construct(public array $digest) {}

    public function via(object $notifiable): array
    {
        return [TenantDatabaseChannel::class];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'daily_digest',
            'title' => 'ملخص الأمس',
            'body' => $this->summarise(),
            'digest' => $this->digest,
        ];
    }

    public function databaseType(): string
    {
        return 'daily_digest';
    }

    /**
     * One line, and the part that needs doing first.
     *
     * What arrived is history; what is waiting is work. A summary that opens
     * with "12 new leads" reads as a good morning even when nine of them have
     * been sitting uncalled since yesterday, so the backlog leads whenever
     * there is one.
     */
    private function summarise(): string
    {
        $parts = [];

        if ($this->digest['uncontacted'] > 0) {
            $parts[] = "{$this->digest['uncontacted']} بانتظار أول تواصل";
        }

        if ($this->digest['overdue_follow_ups'] > 0) {
            $parts[] = "{$this->digest['overdue_follow_ups']} متابعة متأخرة";
        }

        if ($this->digest['silent_sources'] !== []) {
            // Two at most: a line naming six dead sources is a paragraph, and
            // the structured payload carries the rest for the screen to show.
            $labels = array_map(
                fn (array $source) => Platform::labelFor($source['label']),
                array_slice($this->digest['silent_sources'], 0, 2),
            );

            $parts[] = 'لم يصل شيء من '.implode(' و', $labels);
        }

        if ($this->digest['new_leads'] > 0) {
            $parts[] = "{$this->digest['new_leads']} عميل جديد أمس";
        }

        if ($this->digest['won'] > 0) {
            $parts[] = "{$this->digest['won']} صفقة مكسوبة";
        }

        return implode(' · ', $parts);
    }
}
