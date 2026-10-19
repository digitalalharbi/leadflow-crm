<?php

namespace App\Domain\Notifications;

use App\Domain\Leads\LeadViews;
use App\Enums\Role;
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

            if (($lead->wasRecentlyCreated || $lead->wasChanged(['budget_max', 'project_id'])) && ! $lead->status?->isClosed()) {
                self::highValue($lead);
            }
        } catch (\Throwable $e) {
            // An alert never breaks the save that caused it.
            Log::warning('important_alert_failed', ['lead' => $lead->id, 'error' => $e->getMessage()]);
        }
    }

    public static function appointmentRequested(Lead $lead): void
    {
        if (! AlertGate::once("appointment_requested:{$lead->id}")) {
            return;
        }

        self::send(self::recipients($lead), new ImportantNotification(
            'appointment_requested', 'عميل يطلب موعدًا', "{$lead->full_name} طلب موعد زيارة — احجزه الآن.", $lead->uuid, null, 'critical',
        ));
    }

    public static function highValue(Lead $lead): void
    {
        $value = (float) ($lead->budget_max ?? ($lead->project_id ? Project::withoutGlobalScopes()->whereKey($lead->project_id)->value('starting_price') : 0));

        if ($value < LeadViews::HIGH_VALUE_FROM || ! AlertGate::once("high_value:{$lead->id}")) {
            return;
        }

        self::send(self::recipients($lead), new ImportantNotification(
            'high_value_lead', 'عميل عالي القيمة', "{$lead->full_name} — ميزانية تتجاوز ".number_format(LeadViews::HIGH_VALUE_FROM).' ر.س.', $lead->uuid, null, 'warning',
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

        return $owner ? collect([$owner]) : self::managers();
    }

    /** @return Collection<int,User> */
    private static function managers(): Collection
    {
        return User::query()->whereIn('role', [Role::Admin->value, Role::Manager->value])->where('is_active', true)->get();
    }

    private static function send(Collection $users, ImportantNotification $notification): void
    {
        $users->each(fn (User $user) => $user->notify(clone $notification));
    }
}
