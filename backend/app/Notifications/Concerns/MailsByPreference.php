<?php

namespace App\Notifications\Concerns;

use App\Domain\Notifications\AlertKinds;
use App\Domain\Notifications\NotificationPrefs;
use App\Domain\Notifications\StaffWhatsapp;
use App\Enums\Platform;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\Channels\ProviderMailChannel;
use App\Notifications\Channels\StaffWhatsappChannel;
use App\Notifications\Channels\TenantDatabaseChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;

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

        if ($person !== null && filled($person->email) && $mode === 'instant' && $this->withinCooldown($person, $category) && NotificationPrefs::allows($person, $category, 'email')) {
            $channels[] = ProviderMailChannel::class;
        }

        // WhatsApp, for the few who asked for it and only for the critical six.
        if ($category !== null && $notifiable instanceof User && StaffWhatsapp::wants($notifiable, $category)) {
            $channels[] = StaffWhatsappChannel::class;
        }

        return $channels;
    }

    /** Quiet hours hold back the ordinary emails until morning. What is urgent goes through. @return array<string, mixed> */
    public function withDelay(object $notifiable): array
    {
        if (! $notifiable instanceof User || $this->isUrgent()) {
            return [];
        }

        $until = StaffWhatsapp::quietUntil($notifiable);

        return $until ? [ProviderMailChannel::class => $until] : [];
    }

    /** Ten of the same alert in a few minutes is one problem: five emails an hour per kind, the rest stay in the bell and the summary. */
    private function withinCooldown(User $person, string $category): bool
    {
        return $this->isUrgent() || RateLimiter::attempt("alert-mail:{$person->id}:{$category}", 5, fn () => true, 3600);
    }

    private function isUrgent(): bool
    {
        return AlertKinds::priorityOf($this->kind ?? null, $this->alertContent()['severity'] ?? null) === 'critical';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $content = $this->alertContent();
        $severity = $content['severity'];

        return (new MailMessage)
            ->subject($content['title'])
            ->view('emails.alert', [
                'title' => $content['title'],
                'body' => $content['body'],
                'severity' => $severity,
                'category' => $this->alertCategory(),
                'pill' => self::pillFor($severity),
                // The thing itself: the lead, or the screen the alert is about — the home page only when it is neither.
                'link' => rtrim((string) config('integrations.app_url'), '/').($content['lead_id'] ? '/leads/'.$content['lead_id'] : (string) ($content['link'] ?? '')),
                'workspace' => $notifiable->tenant?->name,
            ] + $this->leadActions());
    }

    private static function budgetText(mixed $min, mixed $max): ?string
    {
        $range = array_values(array_unique(array_map(fn ($v) => number_format((float) $v), array_filter([$min, $max], fn ($v) => (float) $v > 0))));

        return $range === [] ? null : implode(' – ', $range).' ريال';
    }

    /** @return array{text:string, tone:string} */
    public static function pillFor(string $severity): array
    {
        return match ($severity) {
            'critical' => ['text' => 'يحتاج تحركاً الآن', 'tone' => 'critical'],
            'warning' => ['text' => 'تنبيه', 'tone' => 'warning'],
            default => ['text' => 'إشعار', 'tone' => 'good'],
        };
    }

    /**
     * What the person needs to act without opening the product: the facts about the lead and a way to reach them.
     * Only for notifications that already carry the lead; nothing is looked up here, so a mail never fails over it.
     *
     * @return array<string, mixed>
     */
    private function leadActions(): array
    {
        $lead = property_exists($this, 'lead') && $this->lead instanceof Lead ? $this->lead : null;

        if ($lead === null) {
            return [];
        }

        $facts = array_filter([
            'العميل' => $lead->full_name,
            'المصدر' => $lead->platform ? Platform::labelFor((string) ($lead->platform instanceof \BackedEnum ? $lead->platform->value : $lead->platform)) : null,
            'المدينة' => $lead->city,
            'الميزانية' => self::budgetText($lead->budget_min, $lead->budget_max),
        ], fn ($v) => filled($v));

        $digits = preg_replace('/\D+/', '', (string) ($lead->phone_normalized ?? $lead->phone));

        return [
            'facts' => $facts,
            'whatsapp' => $digits ? 'https://wa.me/'.$digits : null,
            'call' => $digits ? 'tel:+'.$digits : null,
        ];
    }

    /** Whether a person would be emailed this at once — for tests and for the settings screen's own honesty. */
    public static function categoryFor(?string $kind): ?string
    {
        return AlertKinds::categoryOf($kind);
    }
}
