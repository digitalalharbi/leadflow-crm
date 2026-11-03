<?php

/*
 * How the product's own emails — a report sent to somebody, a statement, a summary — leave the
 * building, behind one door (App\Domain\Mail\MailerProvider).
 *
 * `log` is the default: the message is written down and nothing is sent, and it is reported as
 * "logged", never "sent". The others are ready — written, tested against faked responses — and wait
 * for credentials; none is connected until somebody supplies them and switches the provider.
 */
return [
    // log · smtp · ses · resend. MAIL_MAILER counts when it names a provider, so the runbook's one setting switches both.
    // smtp counts only with a real server named: the framework's default is smtp to nowhere.
    'provider' => env('LEADFLOW_MAIL_PROVIDER', match (true) {
        in_array(env('MAIL_MAILER'), ['resend', 'ses'], true) => env('MAIL_MAILER'),
        env('MAIL_MAILER') === 'smtp' && ! in_array((string) env('MAIL_HOST'), ['', '127.0.0.1', 'localhost', 'mailpit'], true) => 'smtp',
        default => 'log',
    }),

    'from' => [
        'address' => env('LEADFLOW_MAIL_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', 'info@mohtam.pro')),
        'name' => env('LEADFLOW_MAIL_FROM_NAME', 'مهتم — Mohtam'),
    ],

    'reply_to' => env('LEADFLOW_MAIL_REPLY_TO', 'info@mohtam.pro'),

    /*
     * Retries: the seconds to wait before attempt 2, 3, 4… A delivery that fails every time is
     * marked failed after the last, with every attempt on its failure log.
     */
    'backoff' => [60, 300, 1800, 7200],

    // The queue the sends ride on, so a slow provider never holds up anything else.
    'queue' => env('LEADFLOW_MAIL_QUEUE', 'default'),

    'resend' => [
        'key' => env('RESEND_API_KEY'),
        'endpoint' => env('RESEND_ENDPOINT', 'https://api.resend.com/emails'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION'),
    ],
];
