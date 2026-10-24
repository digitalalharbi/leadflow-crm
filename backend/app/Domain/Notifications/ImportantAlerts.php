<?php

namespace App\Domain\Notifications;

use App\Domain\Automation\Thresholds;
use App\Domain\Leads\NextBestAction;
use App\Enums\Platform;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ImportantNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The alerts that are worth a person's attention — and only those.
 *
 * Each is sent once per thing (see AlertGate), to the person who can act:
 * the lead's owner, or the managers when it has none. Never to everybody.
 */
class ImportantAlerts
{
    /** Called after every lead save; cheap unless something relevant changed. */
    public static function leadSaved(Lead $lead): void
    {
        try {
            if ($lead->wasChanged('appointment_interest') && $lead->appointment_interest && ! $lead->status?->isClosed()) {
                self::appointmentRequested($lead);
            }

            // The score put them in the hottest band: somebody should call now.
            if ($lead->wasChanged('temperature') && $lead->temperature?->value === 'hot' && ! $lead->status?->isClosed()) {
                self::hotLead($lead);
            }

            if (($lead->wasRecentlyCreated || $lead->wasChanged(['budget_max', 'project_id'])) && ! $lead->status?->isClosed()) {
                self::highValue($lead);
            }
        } catch (\Throwable $e) {
            // An alert never breaks the save that caused it.
            Log::warning('important_alert_failed', ['lead' => $lead->id, 'error' => $e->getMessage()]);
        }
    }

    /** What the interest engine concluded: a lead became interested, reached "hot" or asked to reserve. Once per lead each. */
    public static function interestChanged(Lead $lead, string $to): void
    {
        try {
            if ($lead->status?->isClosed()) {
                return;
            }

            match ($to) {
                'interested' => self::interestedLead($lead),
                'hot' => self::hotLead($lead),
                'reservation_ready' => self::reservationIntent($lead),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::warning('important_alert_failed', ['lead' => $lead->id, 'error' => $e->getMessage()]);
        }
    }

    public static function hotLead(Lead $lead): void
    {
        if (! AlertGate::once("hot_lead:{$lead->id}")) {
            return;
        }

        self::send(self::recipients($lead), new ImportantNotification(
            'hot_lead', 'عميل باهتمام مرتفع', "{$lead->full_name} صار اهتمامه مرتفعًا".self::about($lead).' — اتصل به الآن.', $lead->uuid, null, 'critical',
        ));
    }

    /** The customer showed they are coming toward us: the owner should be the next voice they hear. Once per lead. */
    public static function interestedLead(Lead $lead): void
    {
        if (! AlertGate::once("interested_lead:{$lead->id}")) {
            return;
        }

        self::send(self::recipients($lead), new ImportantNotification(
            'interested_lead', 'عميل مهتم', "{$lead->full_name} أبدى اهتمامًا".self::about($lead).' — تواصل معه قبل غيرك.'.self::suggestion($lead), $lead->uuid, null, 'warning',
        ));
    }

    /**
     * A lead has arrived and nobody owns it, so nobody has been told: the managers are, once. A lead that
     * has an owner is told to that owner by the assignment itself, and the rest of the floor never hears.
     */
    public static function newLeadWithoutAnOwner(Lead $lead): void
    {
        try {
            if ($lead->assigned_to !== null || $lead->status?->isClosed() || ! AlertGate::once("new_lead:{$lead->id}")) {
                return;
            }

            self::send(self::managers($lead->tenant_id), new ImportantNotification(
                'new_lead', 'عميل جديد بلا مسؤول', "{$lead->full_name}".self::about($lead).' — أسنده إلى مندوب.', $lead->uuid, null, 'warning',
            ));
        } catch (\Throwable $e) {
            Log::warning('important_alert_failed', ['lead' => $lead->id, 'error' => $e->getMessage()]);
        }
    }

    /** What the system would do next with this lead, and why — a sentence, so the alert is also an instruction. */
    private static function suggestion(Lead $lead): string
    {
        try {
            $next = app(NextBestAction::class)->for($lead);
            $reason = trim((string) ($next['reason'] ?? ''));

            return $reason === '' ? '' : " الخطوة المقترحة: {$reason}";
        } catch (\Throwable) {
            return '';
        }
    }

    /** " — مشروع X · عبر ميتا": which project and where the customer came from, for every alert about a lead. */
    public static function about(Lead $lead): string
    {
        $project = $lead->project_id ? ($lead->project?->name ?? Project::withoutGlobalScopes()->whereKey($lead->project_id)->value('name')) : null;
        $platform = $lead->platform ? Platform::labelFor((string) ($lead->platform instanceof \BackedEnum ? $lead->platform->value : $lead->platform)) : null;
        // A project already named "مشروع …" is not called "مشروع مشروع …".
        $project = $project !== null ? preg_replace('/^مشروع\s+/u', '', $project) : null;
        $parts = array_filter([$project ? "مشروع {$project}" : null, $platform ? "عبر {$platform}" : null]);

        return $parts === [] ? '' : ' — '.implode(' · ', $parts);
    }

    public static function reservationIntent(Lead $lead): void
    {
        if (! AlertGate::once("reservation_intent:{$lead->id}")) {
            return;
        }

        self::send(self::recipients($lead), new ImportantNotification(
            'reservation_intent', 'عميل يريد الحجز', "{$lead->full_name} يريد حجز وحدة".self::about($lead).' — أكمل معه الآن.', $lead->uuid, null, 'critical',
        ));
    }

    /** A connected platform that has stopped delivering or reporting: once per platform per day, to the managers. */
    public static function trackingFailure(string $platform, string $label, string $why): void
    {
        if (! AlertGate::once("tracking_failure:{$platform}:".now()->toDateString())) {
            return;
        }

        self::send(self::managers(), new ImportantNotification(
            'tracking_failure', "عطل في التتبع — {$label}", $why, null, '/tracking', 'critical',
        ));
    }

    /** Something about spend or results moved far outside its normal range. One per kind of anomaly per day. */
    public static function budgetAnomaly(string $key, string $title, string $body, ?string $link = '/campaigns'): void
    {
        if (! AlertGate::once("budget_anomaly:{$key}:".now()->toDateString())) {
            return;
        }

        self::send(self::managers(), new ImportantNotification('budget_anomaly', $title, $body, null, $link, 'warning'));
    }

    public static function appointmentRequested(Lead $lead): void
    {
        if (! AlertGate::once("appointment_requested:{$lead->id}")) {
            return;
        }

        self::send(self::recipients($lead), new ImportantNotification(
            'appointment_requested', 'عميل يطلب موعدًا', "{$lead->full_name} طلب موعد زيارة".self::about($lead).' — احجزه الآن.', $lead->uuid, null, 'critical',
        ));
    }

    public static function highValue(Lead $lead): void
    {
        $value = (float) ($lead->budget_max ?? ($lead->project_id ? Project::withoutGlobalScopes()->whereKey($lead->project_id)->value('starting_price') : 0));
        // The line is the workspace's, or the project's own.
        $from = Thresholds::forLead($lead, 'high_value_from');

        if ($value < $from || ! AlertGate::once("high_value:{$lead->id}")) {
            return;
        }

        self::send(self::recipients($lead), new ImportantNotification(
            'high_value_lead', 'عميل عالي القيمة', "{$lead->full_name}".self::about($lead).' — ميزانية تتجاوز '.number_format($from).' ر.س.', $lead->uuid, null, 'warning',
        ));
    }

    /** One per rule per day: a rule that fails on forty leads is one message, not forty. */
    public static function automationFailed(?int $ruleId, ?string $ruleName, ?string $why): void
    {
        if (! AlertGate::once('automation_failed:'.($ruleId ?? $ruleName ?? 'unknown').':'.now()->toDateString())) {
            return;
        }

        self::send(self::managers(), new ImportantNotification(
            'automation_failed', 'فشل في الأتمتة', trim(($ruleName ? "القاعدة «{$ruleName}»: " : '').($why ?? 'لم يُنفَّذ إجراء.')), null, '/automation', 'warning',
        ));
    }

    /** @return Collection<int,User> */
    private static function recipients(Lead $lead): Collection
    {
        $owner = $lead->assigned_to ? User::query()->where('tenant_id', $lead->tenant_id)->where('is_active', true)->find($lead->assigned_to) : null;

        return $owner ? collect([$owner]) : self::managers($lead->tenant_id);
    }

    /**
     * This workspace's managers — the lead's when there is one, otherwise the one in context.
     *
     * @return Collection<int,User>
     */
    private static function managers(?int $tenantId = null): Collection
    {
        return User::query()->inWorkspace($tenantId)->managers()->get();
    }

    private static function send(Collection $users, ImportantNotification $notification): void
    {
        $users->each(fn (User $user) => $user->notify(clone $notification));
    }
}
