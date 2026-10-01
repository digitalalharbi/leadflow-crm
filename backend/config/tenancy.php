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

    /*
     * Platform owner. Read through config so `config:cache` cannot turn these
     * into null at runtime, which is how a default password slips into production.
     */
    'super_admin' => [
        'email' => env('SUPER_ADMIN_EMAIL', 'owner@example.com'),
        'name' => env('SUPER_ADMIN_NAME', 'Platform Owner'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
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
