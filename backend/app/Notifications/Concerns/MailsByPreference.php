<?php

namespace App\Notifications\Concerns;

use App\Domain\Notifications\AlertKinds;
use App\Domain\Notifications\NotificationPrefs;
use App\Models\User;
use App\Notifications\Channels\TenantDatabaseChannel;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Adds the email to a notification — for the people who asked for it to arrive at once.
 *
 * The in-app notification is always made; the email only when this person's setting for this
 * kind of alert is "instant" (the other settings put it in a digest instead, which reads what the
 * app already stored). Nothing is mailed to somebody with no address, or when the notification
 * does not belong to one of the eight alert categories.
 */
trait MailsByPreference
{
    /** The alert category this notification belongs to, or null when it is not one worth an email. */
    abstract public function alertCategory(): ?string;

    /** @return array{title:string, body:string, lead_id:?string, severity:string} */
    abstract public function alertContent(): array;

    public function via(object $notifiable): array
    {
        $channels = [TenantDatabaseChannel::class];
        $category = $this->alertCategory();

        if ($category !== null && $notifiable instanceof User && filled($notifiable->email) && NotificationPrefs::modeFor($notifiable, $category) === 'instant') {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $content = $this->alertContent();

        return (new MailMessage)
            ->subject($content['title'])
            ->view('emails.alert', [
                'title' => $content['title'],
                'body' => $content['body'],
                'severity' => $content['severity'],
                'category' => $this->alertCategory(),
                'link' => $content['lead_id'] ? rtrim((string) config('integrations.app_url'), '/').'/leads/'.$content['lead_id'] : rtrim((string) config('integrations.app_url'), '/'),
                'workspace' => $notifiable->tenant?->name,
            ]);
    }

    /** Whether a person would be emailed this at once — for tests and for the settings screen's own honesty. */
    public static function categoryFor(?string $kind): ?string
    {
        return AlertKinds::categoryOf($kind);
    }
}
