<?php

return [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'oauth_redirect_uri' => env('GOOGLE_OAUTH_REDIRECT_URI', 'http://localhost:8000/google/oauth/callback'),

    'scopes' => [
        'openid',
        'email',
        'profile',
        'https://www.googleapis.com/auth/calendar',
    ],

    'webhook_enabled' => env('GOOGLE_WEBHOOK_ENABLED', false),
    'webhook_url' => env('GOOGLE_WEBHOOK_URL'),

    'sync_lookback_days' => (int) env('GOOGLE_SYNC_LOOKBACK_DAYS', 365),

    'default_event_type_id' => env('GOOGLE_DEFAULT_EVENT_TYPE_ID'),

    'calendar_summary_own' => 'Lavoro',
    'calendar_summary_granted_template' => ':name — Lavoro',
];
