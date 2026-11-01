<?php

namespace App\Notifications\Concerns;

use App\Domain\Notifications\AlertKinds;
use App\Domain\Notifications\NotificationPrefs;
use App\Domain\Notifications\StaffWhatsapp;
use App\Models\User;
use App\Notifications\Channels\ProviderMailChannel;
use App\Notifications\Channels\StaffWhatsappChannel;
use App\Notifications\Channels\TenantDatabaseChannel;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Adds the email to a notification — for the people who asked for it to arrive at once.
 *
 * The in-app notification is always made; the email only when this person's setting for this
 * kind of alert is "instant" (the other settings put it in a digest instead, which reads what the
 * app already stored). Nothing is mailed to somebody with no address, or when the notification
 * does not belong to one of the eight alert categories. The email goes through the product's own
 * door (ProviderMailChannel → EmailDeliveries), never Laravel's mail channel.
 */
trait MailsByPreference
{
    /** The alert category this notification belongs to, or null when it is not one worth an email. */
    abstract public function alertCategory(): ?string;

    /** @return array{title:string, body:string, lead_id:?string, severity:string, link?:?string} */
    abstract public function alertContent(): array;

    public function via(object $notifiable): array
    {
        $category = $this->alertCategory();
        $person = $category !== null && $notifiable instanceof User ? $notifiable : null;

        /*
         * The bell, unless the person switched it off for this kind of alert. Still recorded when the alert is
         * meant for a daily or weekly email: the summary is built from these records, and switching the bell off
         * must not empty the summary the person asked for.
         */
        $mode = $person !== null ? NotificationPrefs::modeFor($person, $category) : null;
        $forSummary = in_array($mode, ['daily', 'weekly'], true) && NotificationPrefs::allows($person, $category, 'email');
        $channels = $person === null || $forSummary || NotificationPrefs::allows($person, $category, 'in_app') ? [TenantDatabaseChannel::class] : [];

        if ($person !== null && filled($person->email) && $mode === 'instant' && NotificationPrefs::allows($person, $category, 'email')) {
            $channels[] = ProviderMailChannel::class;
        }

        // WhatsApp, for the few who asked for it and only for the critical six.
        if ($category !== null && $notifiable instanceof User && StaffWhatsapp::wants($notifiable, $category)) {
            $channels[] = StaffWhatsappChannel::class;
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
                // The thing itself: the lead, or the screen the alert is about — the home page only when it is neither.
                'link' => rtrim((string) config('integrations.app_url'), '/').($content['lead_id'] ? '/leads/'.$content['lead_id'] : (string) ($content['link'] ?? '')),
                'workspace' => $notifiable->tenant?->name,
            ]);
    }

    /** Whether a person would be emailed this at once — for tests and for the settings screen's own honesty. */
    public static function categoryFor(?string $kind): ?string
    {
        return AlertKinds::categoryOf($kind);
    }
}
