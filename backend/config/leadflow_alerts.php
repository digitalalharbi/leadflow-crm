<?php

/*
 * Alerts to staff on WhatsApp — optional, opt-in, and only the critical few.
 *
 * Every message is bounded twice (an hour and a day, per person) so a busy morning cannot turn a
 * phone into a siren, and every attempt — sent, failed, or held back and why — is written to
 * staff_alert_logs.
 */
return [
    'whatsapp' => [
        'per_hour' => (int) env('STAFF_WHATSAPP_PER_HOUR', 6),
        'per_day' => (int) env('STAFF_WHATSAPP_PER_DAY', 20),
        // Seconds to wait before attempt 2, 3 … for a failure worth another try.
        'backoff' => [60, 300],
        'queue' => env('STAFF_WHATSAPP_QUEUE', 'default'),
    ],
];
