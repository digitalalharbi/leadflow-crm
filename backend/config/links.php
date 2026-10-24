<?php

/*
 * The addresses Mohtam hands to people outside the workspace, and how they are built.
 *
 * One layer, one short shape: a prefix and a token — never an internal id, never a tenant or project name.
 *
 *   /r/<token>   a report         /f/<token>   a form        /t/<token>   a tracking link
 *   /i/<token>   an invitation    /s/<token>   a shared resource (a file, or any link the layer knows)
 *
 * `public_base` is where they live today (https://mohtam.pro). `short_base` is the shorter domain they will move to
 * (https://go.mohtam.pro) the day it is switched on: flipping LINKS_SHORT_ENABLED changes only the addresses that are
 * *issued* from then on — every address already sent keeps working, because the routes answer on every host.
 */
return [
    'public_base' => env('LINKS_PUBLIC_BASE'),

    'short_base' => env('LINKS_SHORT_BASE', 'https://go.mohtam.pro'),

    'short_enabled' => (bool) env('LINKS_SHORT_ENABLED', false),

    /* Tracking links are followed by a redirect served by the API; production proxies /t on the public domain to it. */
    'tracking_base' => env('LINKS_TRACKING_BASE'),

    'prefixes' => [
        'report' => 'r',
        'form' => 'f',
        'tracking' => 't',
        'invitation' => 'i',
        'share' => 's',
    ],

    /* 22 characters of base 62: about 131 bits. A guess is not a plan. */
    'token_length' => 22,

    /* A password-protected link allows this many wrong passwords a minute from one address. */
    'password_attempts_per_minute' => 10,

    /* The code sent to an allowed email lives this long, and a link keeps the visitor in for this long after. */
    'otp_minutes' => 10,
    'otp_session_hours' => 12,

    // How long one report's numbers are kept before they are built again (never longer than the report's own refresh
    // rate). A link opened a hundred times a minute costs one build, not a hundred. 0 turns it off.
    'report_cache_seconds' => (int) env('REPORT_CACHE_SECONDS', 20),
];
