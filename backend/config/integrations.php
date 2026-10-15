<?php

/*
 * Platform-wide API credentials. Tenants can override per-integration; when both
 * are empty the provider runs in sandbox mode and the Integrations page shows
 * "configuration required" instead of failing. See EXTERNAL_SETUP.md.
 */
return [
    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'verify_token' => env('META_VERIFY_TOKEN'),
    ],
    'tiktok' => [
        'app_id' => env('TIKTOK_APP_ID'),
        'app_secret' => env('TIKTOK_APP_SECRET'),
    ],
    'google_ads' => [
        'client_id' => env('GOOGLE_ADS_CLIENT_ID'),
        'client_secret' => env('GOOGLE_ADS_CLIENT_SECRET'),
        'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),
    ],
    'google_sheets' => [
        'client_id' => env('GOOGLE_SHEETS_CLIENT_ID'),
        'client_secret' => env('GOOGLE_SHEETS_CLIENT_SECRET'),
    ],
    'linkedin' => [
        'client_id' => env('LINKEDIN_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
    ],
    'snapchat' => [
        'client_id' => env('SNAPCHAT_CLIENT_ID'),
        'client_secret' => env('SNAPCHAT_CLIENT_SECRET'),
    ],
    /*
     * Credential-stuffing guard. Deliberately low by default. Raise it only in
     * an environment where an automated suite signs in repeatedly — never in
     * production.
     */
    /*
     * General API throttle, applied to every authenticated endpoint. Keyed per
     * user when authenticated, per IP otherwise. Configurable because a browser
     * suite drives hundreds of requests a minute from one address.
     */
    'api' => [
        'per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 120),
    ],

    'login' => [
        'per_email_per_minute' => (int) env('LOGIN_RATE_LIMIT_PER_MINUTE', 5),
        'per_ip_per_minute' => (int) env('LOGIN_RATE_LIMIT_PER_IP_PER_MINUTE', 20),
    ],

    /*
     * WhatsApp callbacks are far chattier than lead ingestion — a single
     * conversation produces a message plus sent, delivered and read receipts —
     * and they arrive from Meta's own address range, so the meaningful bucket
     * is the phone number, not the caller.
     */
    'whatsapp' => [
        'per_number_per_minute' => (int) env('WHATSAPP_RATE_LIMIT_PER_NUMBER', 600),
        'per_ip_per_minute' => (int) env('WHATSAPP_RATE_LIMIT_PER_IP', 3000),

        /*
         * Outbound, per conversation. Generous enough that a rep in a live
         * exchange never notices, and low enough that a stuck client or a
         * stolen token cannot turn one buyer into a complaint — and a reported
         * number is the end of this channel for the tenant who owns it.
         */
        'messages_per_lead_per_minute' => (int) env('WHATSAPP_MESSAGES_PER_LEAD', 10),
    ],

    /*
     * Where a browser is sent back to after an external consent screen.
     *
     * The API and the interface are one origin in production and two in
     * development, and the OAuth callback is the one place that difference
     * escapes the proxy: Google redirects the browser itself, so it lands on
     * the API and has to be forwarded to wherever the app actually is.
     */
    'app_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost')),

    'webhooks' => [
        'max_body_bytes' => (int) env('WEBHOOK_MAX_BODY_BYTES', 65536),
        'rate_limit_per_minute' => (int) env('WEBHOOK_RATE_LIMIT_PER_MINUTE', 120),
    ],
];
