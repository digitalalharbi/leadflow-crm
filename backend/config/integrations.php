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

    'webhooks' => [
        'max_body_bytes' => (int) env('WEBHOOK_MAX_BODY_BYTES', 65536),
        'rate_limit_per_minute' => (int) env('WEBHOOK_RATE_LIMIT_PER_MINUTE', 120),
    ],
];
