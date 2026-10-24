<?php

return [
    /*
     * Who takes the money. 'manual' means the platform owner activates and extends subscriptions from the console and
     * records invoices by hand; a payment provider plugs in behind App\Domain\Billing\BillingProvider without changing
     * the subscription model.
     */
    'provider' => env('BILLING_PROVIDER', 'manual'),

    /* Days a past-due company keeps working after its paid period ends, with the warning on every screen. */
    'past_due_grace_days' => (int) env('BILLING_PAST_DUE_GRACE_DAYS', 14),

    /* Roles that are a window for someone outside the company. They do not take a paid seat. */
    'seatless_roles' => ['client_viewer', 'broker_viewer'],

    /* At this share of a limit, the owner's console and the company's billing page raise a usage alert. */
    'usage_alert_ratio' => 0.8,
];
