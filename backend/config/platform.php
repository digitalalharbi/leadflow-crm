<?php

return [
    /*
     * The platform owner's sign-in is its own door (/api/v1/platform/auth/login), never a company's. With this on, an
     * owner account without a second factor can sign in only to enrol one; nothing else in the console answers it.
     */
    // Other ways to sign in, shown only when listed here with a working flow behind them: [key, label, url]. None yet.
    'login_providers' => [],

    // What reaches outside a company waits for a proven address, once mail really goes out.
    'require_verified_email' => (bool) env('PLATFORM_REQUIRE_VERIFIED_EMAIL', true),

    'require_mfa' => (bool) env('PLATFORM_REQUIRE_MFA', false),

    /* How long a console session lasts before signing in again. */
    'session_hours' => (int) env('PLATFORM_SESSION_HOURS', 12),

    /* A support session inside a company ends on its own after this many minutes. */
    'impersonation_minutes' => (int) env('PLATFORM_IMPERSONATION_MINUTES', 30),

    /* The name an authenticator app shows beside the code. */
    'mfa_issuer' => env('PLATFORM_MFA_ISSUER', 'مهتم'),
];
