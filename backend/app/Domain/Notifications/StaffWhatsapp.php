<?php

namespace App\Domain\Notifications;

use App\Jobs\SendStaffWhatsappAlert;
use App\Models\StaffAlertLog;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Leads\PhoneNormalizer;
use App\Services\Whatsapp\SendFailure;
use App\Services\Whatsapp\WhatsappManager;
use Carbon\CarbonImmutable;

/**
 * WhatsApp alerts to a member of staff — a person's own choice, for the critical few, never a
 * broadcast.
 *
 * A message is sent only when the person switched it on, chose "instant" for that category, has a
 * phone on their profile and the workspace has a WhatsApp number that can send. Then it is held
 * back, and the reason written down, when it falls in the person's quiet hours, when they have
 * already had their share this hour or day, or when they were told this very thing already. The
 * in-app notification and the email are untouched by any of that.
 */
class StaffWhatsapp
{
    public const MODES = ['instant', 'off'];

    public function __construct(private WhatsappManager $manager, private PhoneNormalizer $phones) {}

    /** The email defaults, narrowed to the six: what a person hears at once by email is what they hear here once switched on. @return array<string,string> */
    public static function defaultAlerts(User $user): array
    {
        $email = NotificationPrefs::defaults($user)['alerts'];

        return collect(AlertKinds::STAFF_WHATSAPP)->mapWithKeys(fn (string $c) => [$c => ($email[$c] ?? 'off') === 'instant' ? 'instant' : 'off'])->all();
    }

    /**
     * What is in force for this person: switched off until they switch it on.
     *
     * @return array{enabled:bool, alerts:array<string,string>, quiet:array{enabled:bool,start:string,end:string,source:string}}
     */
    public static function prefs(User $user): array
    {
        $own = (array) (((array) ($user->notification_prefs ?? []))['whatsapp'] ?? []);
        $workspace = TenantSetting::query()->where('tenant_id', $user->tenant_id)->first();
        $quiet = (array) ($own['quiet'] ?? []);

        return [
            'enabled' => (bool) ($own['enabled'] ?? false),
            'alerts' => collect(self::defaultAlerts($user))->map(fn (string $mode, string $c) => in_array($own['alerts'][$c] ?? null, self::MODES, true) ? $own['alerts'][$c] : $mode)->all(),
            // The person's own quiet hours, else the workspace's — the same window customers are protected by.
            'quiet' => $quiet !== [] ? [
                'enabled' => (bool) ($quiet['enabled'] ?? false), 'start' => (string) ($quiet['start'] ?? '22:00'), 'end' => (string) ($quiet['end'] ?? '07:00'), 'source' => 'own',
            ] : [
                'enabled' => (bool) ($workspace?->quiet_hours_enabled ?? false), 'start' => (string) ($workspace?->quiet_hours_start ?? '21:00'), 'end' => (string) ($workspace?->quiet_hours_end ?? '08:00'), 'source' => 'workspace',
            ],
        ];
    }

    /** @param  array{enabled?:bool, alerts?:array<string,string>, quiet?:?array<string,mixed>}  $changes */
    public static function save(User $user, array $changes): array
    {
        $all = (array) ($user->notification_prefs ?? []);
        $own = (array) ($all['whatsapp'] ?? []);

        if (array_key_exists('enabled', $changes)) {
            $own['enabled'] = (bool) $changes['enabled'];
        }

        foreach ($changes['alerts'] ?? [] as $category => $mode) {
            if (in_array($category, AlertKinds::STAFF_WHATSAPP, true) && in_array($mode, self::MODES, true)) {
                $own['alerts'][$category] = $mode;
            }
        }

        if (array_key_exists('quiet', $changes)) {
            if ($changes['quiet'] === null) {
                // Back to the workspace's window.
                unset($own['quiet']);
            } else {
                // A change to one edge keeps the other as it is in force now (their own, or the workspace's they are starting from).
                $now = self::prefs($user)['quiet'];
                $own['quiet'] = [
                    'enabled' => (bool) ($changes['quiet']['enabled'] ?? $now['enabled']),
                    'start' => (string) ($changes['quiet']['start'] ?? $now['start']),
                    'end' => (string) ($changes['quiet']['end'] ?? $now['end']),
                ];
            }
        }

        $all['whatsapp'] = $own;
        $user->forceFill(['notification_prefs' => $all])->save();

        return self::prefs($user);
    }

    /** Whether this person wants this category on WhatsApp at once — the cheap check a notification asks first. */
    public static function wants(User $user, string $category): bool
    {
        if (! in_array($category, AlertKinds::STAFF_WHATSAPP, true) || ! filled($user->phone)) {
            return false;
        }

        $prefs = self::prefs($user);

        return $prefs['enabled'] && ($prefs['alerts'][$category] ?? 'off') === 'instant';
    }

    /** The end of the person's quiet window when `$at` is inside it. */
    public static function quietUntil(User $user, ?CarbonImmutable $at = null): ?CarbonImmutable
    {
        $quiet = self::prefs($user)['quiet'];

        if (! $quiet['enabled']) {
            return null;
        }

        $tz = TenantSetting::query()->where('tenant_id', $user->tenant_id)->value('timezone') ?: 'Asia/Riyadh';
        $now = ($at ?? CarbonImmutable::now())->setTimezone($tz);
        [$sh, $sm] = array_map('intval', explode(':', $quiet['start']));
        [$eh, $em] = array_map('intval', explode(':', $quiet['end']));
        $minutes = $now->hour * 60 + $now->minute;
        $start = $sh * 60 + $sm;
        $end = $eh * 60 + $em;
        $inside = $start <= $end ? ($minutes >= $start && $minutes < $end) : ($minutes >= $start || $minutes < $end);

        if (! $inside) {
            return null;
        }

        $until = $now->setTime($eh, $em);

        return $until->lte($now) ? $until->addDay() : $until;
    }

    /**
     * One alert for one person: decide, write it down, and hand it to the queue if it is going.
     * Always leaves a row, so "why did I not get it?" has an answer.
     */
    public function queue(User $user, string $category, string $title, string $body, string $dedupKey): StaffAlertLog
    {
        $log = new StaffAlertLog(['user_id' => $user->id, 'category' => $category, 'dedup_key' => mb_substr($dedupKey, 0, 190), 'title' => mb_substr($title, 0, 160), 'status' => StaffAlertLog::QUEUED]);
        $integration = $this->manager->integration();

        $why = match (true) {
            ! in_array($category, AlertKinds::STAFF_WHATSAPP, true) => 'opted_out',
            ! filled($user->phone) || $this->phones->normalize($user->phone) === null => 'no_phone',
            ! $this->manager->canSend($integration) => 'not_connected',
            self::quietUntil($user) !== null => 'quiet_hours',
            $this->seen($user, $dedupKey) => 'duplicate',
            $this->overLimit($user) => 'rate_limited',
            default => null,
        };

        if ($why !== null) {
            $log->fill(['status' => StaffAlertLog::SKIPPED, 'reason' => $why])->save();

            return $log;
        }

        $log->fill(['provider' => $this->manager->providerFor($integration)->key()])->save();
        // The text rides in the job's argument, not in the table: the record keeps the title, not the customer's details.
        SendStaffWhatsappAlert::dispatch($log->id, $body)->onQueue((string) config('leadflow_alerts.whatsapp.queue', 'default'))->afterCommit();

        return $log;
    }

    /** One attempt: ask the provider once and record the answer; a failure worth another try waits on the backoff. */
    public function attempt(int $logId, string $body): ?StaffAlertLog
    {
        $log = StaffAlertLog::query()->whereKey($logId)->first();

        if ($log === null || $log->status !== StaffAlertLog::QUEUED) {
            return null;
        }

        $user = User::query()->find($log->user_id);
        $integration = $this->manager->integration();
        $to = $user ? $this->phones->toWhatsApp($this->phones->normalize($user->phone)) : null;

        if ($user === null || $to === null || ! $this->manager->canSend($integration)) {
            $log->fill(['status' => StaffAlertLog::SKIPPED, 'reason' => $to === null ? 'no_phone' : 'not_connected'])->save();

            return $log;
        }

        $provider = $this->manager->providerFor($integration);
        $result = $provider->sendText($integration, $to, $body);
        $n = $log->attempts + 1;

        if ($result->accepted) {
            $log->fill(['attempts' => $n, 'status' => StaffAlertLog::SENT, 'provider' => $provider->key(), 'provider_message_id' => $result->providerMessageId, 'sent_at' => now(), 'error' => null])->save();

            return $log;
        }

        $backoff = (array) config('leadflow_alerts.whatsapp.backoff', []);

        if (SendFailure::isTransient($result->failureCode) && $n <= count($backoff)) {
            $log->fill(['attempts' => $n, 'error' => mb_substr("{$result->failureCode}: {$result->failureReason}", 0, 500)])->save();
            SendStaffWhatsappAlert::dispatch($log->id, $body)->onQueue((string) config('leadflow_alerts.whatsapp.queue', 'default'))->delay(now()->addSeconds((int) $backoff[$n - 1]));

            return $log;
        }

        $log->fill([
            'attempts' => $n, 'status' => StaffAlertLog::FAILED, 'provider' => $provider->key(),
            'reason' => SendFailure::isOutsideWindow($result->failureCode) ? 'outside_window' : (in_array($result->failureCode, ['131026'], true) ? 'undeliverable' : 'provider_error'),
            'error' => mb_substr("{$result->failureCode}: {$result->failureReason}", 0, 500),
        ])->save();

        return $log;
    }

    /** Told this very thing already — today, by a message that was sent or is on its way. */
    private function seen(User $user, string $dedupKey): bool
    {
        return StaffAlertLog::query()->where('user_id', $user->id)->where('dedup_key', mb_substr($dedupKey, 0, 190))
            ->whereIn('status', [StaffAlertLog::QUEUED, StaffAlertLog::SENT])->where('created_at', '>=', now()->subDay())->exists();
    }

    /** More than their share this hour or this day. */
    private function overLimit(User $user): bool
    {
        $counted = fn (int $minutes) => StaffAlertLog::query()->where('user_id', $user->id)->whereIn('status', [StaffAlertLog::QUEUED, StaffAlertLog::SENT])->where('created_at', '>=', now()->subMinutes($minutes))->count();

        return $counted(60) >= (int) config('leadflow_alerts.whatsapp.per_hour', 6) || $counted(1440) >= (int) config('leadflow_alerts.whatsapp.per_day', 20);
    }
}
