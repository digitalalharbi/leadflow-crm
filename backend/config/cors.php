<?php

/*
 * Cross-origin requests.
 *
 * The API authenticates with a Bearer token, never a cookie (see bootstrap/app.php: Sanctum's cookie mode is off), so
 * a page on another origin gains nothing from CORS without the token itself. Origins stay open on purpose: the tracking
 * beacon and the public landing endpoints are called from customers' own websites, which no list here could know.
 * Credentials are never allowed cross-origin.
 */
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*'))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['Content-Disposition'],
    'max_age' => 3600,
    'supports_credentials' => false,
];
