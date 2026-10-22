<?php

namespace App\Domain\Notifications;

/**
 * The things worth an email, and which notification belongs to which.
 *
 * A notification carries a `kind` of its own ("interest_sla_breached"); a person chooses how to
 * hear about a *category* ("an SLA was breached"). This is the one place that says which is which,
 * so the settings screen, the sender and the digests cannot disagree about what a setting covers.
 */
class AlertKinds
{
    public const CATEGORIES = ['new_lead', 'hot_lead', 'interested_lead', 'visit_requested', 'reservation_intent', 'sla_breach', 'automation_failure', 'high_value', 'tracking_failure', 'budget_anomaly'];

    /** How a person can hear about a category. */
    public const MODES = ['instant', 'daily', 'weekly', 'off'];

    public const DIGESTS = ['daily_sales', 'weekly_marketing', 'weekly_executive', 'monthly_executive'];

    /** The six things worth a WhatsApp message to a member of staff — critical ones only. */
    public const STAFF_WHATSAPP = ['hot_lead', 'visit_requested', 'reservation_intent', 'sla_breach', 'high_value', 'automation_failure'];

    private const KIND_TO_CATEGORY = [
        'new_lead' => 'new_lead',
        'interested_lead' => 'interested_lead',
        'hot_lead' => 'hot_lead',
        'appointment_requested' => 'visit_requested',
        'reservation_intent' => 'reservation_intent',
        'interest_sla_due_soon' => 'sla_breach',
        'interest_sla_breached' => 'sla_breach',
        'interest_sla_escalated' => 'sla_breach',
        'sla' => 'sla_breach',
        'automation_failed' => 'automation_failure',
        'delivery_failed' => 'automation_failure',
        'high_value_lead' => 'high_value',
        'tracking_failure' => 'tracking_failure',
        'budget_anomaly' => 'budget_anomaly',
    ];

    /** The category a notification kind belongs to, or null for the ones that are not email-worthy. */
    public static function categoryOf(?string $kind): ?string
    {
        return self::KIND_TO_CATEGORY[$kind] ?? null;
    }

    /** @return list<string> every notification kind in a category */
    public static function kindsOf(string $category): array
    {
        return array_keys(array_filter(self::KIND_TO_CATEGORY, fn (string $c) => $c === $category));
    }
}
