<?php

return [
    /*
     * Root domain for subdomain tenant resolution: client1.<central_domain>.
     * Custom domains are intentionally out of scope for the MVP.
     */
    'central_domain' => env('APP_CENTRAL_DOMAIN', 'localhost'),

    /* Subdomains that can never belong to a tenant. */
    'reserved_slugs' => [
        'www', 'api', 'app', 'admin', 'staging', 'staging-api', 'demo',
        'mail', 'cdn', 'assets', 'status', 'docs', 'support', 'help',
    ],

    'defaults' => [
        'plan' => 'trial',
        'trial_days' => 14,
        'locale' => 'ar',
        'currency' => 'SAR',
        'timezone' => 'Asia/Riyadh',
        'country_code' => '966',
    ],
];
