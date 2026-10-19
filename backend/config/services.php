<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
     * WhatsApp Business Platform. Nothing here is a credential: every tenant
     * brings its own token, phone number id and app secret, stored encrypted on
     * its integration row. These are only the endpoint and its limits.
     */
    'whatsapp' => [
        'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v21.0'),
        'timeout' => env('WHATSAPP_TIMEOUT', 15),
        // Longest message we will accept from the composer. The Cloud API caps
        // a text body at 4096 characters.
        'max_body_length' => 4096,
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * GitHub, for the development activity shown inside a project.
     *
     * No client id or secret: a workspace connects with its own fine-grained
     * token, which is granted repository by repository and stored encrypted on
     * the integration row. There is nothing here for the platform to own, and
     * nothing that would need a GitHub App to be registered before anybody can
     * use it.
     */
    'github' => [
        'api_url' => env('GITHUB_API_URL', 'https://api.github.com'),
        'timeout' => (int) env('GITHUB_TIMEOUT', 15),
    ],

    /*
     * Claude, for the agents. A workspace may bring its own key (stored
     * encrypted on its settings); the platform key here is the fallback and may
     * be absent, in which case the agents answer from their rules and say so.
     *
     * Models by role, as the build plan sets them: the strongest for the
     * agents that decide, a fast one for the agents that repeat.
     */
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => '2023-06-01',
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 60),
        'max_tool_rounds' => 6,
        'max_tokens' => 2048,
        'models' => [
            'orchestrator' => env('AGENT_MODEL_ORCHESTRATOR', 'claude-opus-5-5'),
            'campaign' => env('AGENT_MODEL_CAMPAIGN', 'claude-opus-5-5'),
            'analyst' => env('AGENT_MODEL_ANALYST', 'claude-sonnet-5-5'),
            'creative' => env('AGENT_MODEL_CREATIVE', 'claude-sonnet-5-5'),
            'leads' => env('AGENT_MODEL_LEADS', 'claude-haiku-5-5'),
            'reports' => env('AGENT_MODEL_REPORTS', 'claude-haiku-5-5'),
        ],
    ],

];
