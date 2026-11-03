<?php

namespace App\Domain\Notifications;

use App\Models\User;

/**
 * A person's choices about being told, with sensible defaults for what they do.
 *
 * What a rep needs at once is the customer who wants to buy now; what a manager needs at once is
 * also that something is broken (SLA, automation, tracking). The rest is better as a summary:
 * a high-value lead and a budget anomaly are read once a day, not acted on in the minute.
 * Anyone can change any of it; nothing here is a permission.
 */
class NotificationPrefs
{
    /** The channels a person switches per kind of alert. WhatsApp has its own opt-in, quiet hours and caps. */
    public const CHANNELS = ['in_app', 'email'];

    private const REP_INSTANT = ['hot_lead', 'interested_lead', 'visit_requested', 'reservation_intent', 'sla_breach', 'appointment', 'task_due', 'reservation'];

    private const MANAGER_INSTANT = ['hot_lead', 'interested_lead', 'visit_requested', 'reservation_intent', 'sla_breach', 'automation_failure', 'tracking_failure', 'appointment', 'reservation', 'integration_failure'];

    /** @return array{alerts: array<string,string>, digests: array<string,bool>} */
    public static function defaults(User $user): array
    {
        $manages = $user->isAdmin() || $user->isManager();
        $instant = $manages ? self::MANAGER_INSTANT : self::REP_INSTANT;
        // A new lead reaches its owner in the app and does the work by itself; a rep is not mailed every one,
        // and a manager hears of the ones nobody owns in the day's summary.
        $daily = $manages ? ['new_lead', 'high_value', 'budget_anomaly', 'sale', 'task_due'] : [];

        return [
            'alerts' => collect(AlertKinds::CATEGORIES)->mapWithKeys(fn (string $c) => [
                $c => in_array($c, $instant, true) ? 'instant' : (in_array($c, $daily, true) ? 'daily' : 'off'),
            ])->all(),
            'digests' => [
                'daily_sales' => true,
                'weekly_marketing' => $manages,
                'weekly_executive' => $user->isAdmin(),
                'monthly_executive' => $user->isAdmin(),
            ],
        ];
    }

    /** What is in force: the person's own choices laid over the defaults. @return array{alerts: array<string,string>, digests: array<string,bool>} */
    public static function for(User $user): array
    {
        $defaults = self::defaults($user);
        $own = (array) ($user->notification_prefs ?? []);

        return [
            'alerts' => collect($defaults['alerts'])->map(fn (string $mode, string $category) => in_array($own['alerts'][$category] ?? null, AlertKinds::MODES, true) ? $own['alerts'][$category] : $mode)->all(),
            'digests' => collect($defaults['digests'])->map(fn (bool $on, string $digest) => isset($own['digests'][$digest]) ? (bool) $own['digests'][$digest] : $on)->all(),
            /*
             * Where an alert may reach the person, per kind and per channel. Both on unless they said otherwise:
             * the mode above still decides whether the email goes at once, in a summary, or never; this decides
             * whether that channel is used at all — "tell me about hot leads by email but not in the bell".
             */
            'channels' => collect(AlertKinds::CATEGORIES)->mapWithKeys(fn (string $c) => [$c => [
                'in_app' => (bool) ($own['channels'][$c]['in_app'] ?? true),
                'email' => (bool) ($own['channels'][$c]['email'] ?? true),
            ]])->all(),
        ];
    }

    public static function modeFor(User $user, string $category): string
    {
        return self::for($user)['alerts'][$category] ?? 'off';
    }

    /** Whether this kind of alert may use this channel for this person. */
    public static function allows(User $user, string $category, string $channel): bool
    {
        return self::for($user)['channels'][$category][$channel] ?? true;
    }

    public static function wantsDigest(User $user, string $digest): bool
    {
        return self::for($user)['digests'][$digest] ?? false;
    }

    /** @param  array{alerts?: array<string,string>, digests?: array<string,bool>}  $changes */
    public static function save(User $user, array $changes): array
    {
        $own = (array) ($user->notification_prefs ?? []);

        foreach ($changes['alerts'] ?? [] as $category => $mode) {
            $own['alerts'][$category] = $mode;
        }

        foreach ($changes['digests'] ?? [] as $digest => $on) {
            $own['digests'][$digest] = (bool) $on;
        }

        foreach ($changes['channels'] ?? [] as $category => $channels) {
            foreach (array_intersect_key((array) $channels, array_flip(self::CHANNELS)) as $channel => $on) {
                $own['channels'][$category][$channel] = (bool) $on;
            }
        }

        $user->forceFill(['notification_prefs' => $own])->save();

        return self::for($user);
    }
}
