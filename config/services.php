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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'graph' => [
        'tenant_id' => env('GRAPH_TENANT_ID'),
        'client_id' => env('GRAPH_CLIENT_ID'),
        'client_secret' => env('GRAPH_CLIENT_SECRET'),
        'user_id' => env('GRAPH_USER_ID'), // optional override (UPN or GUID)
        'endpoint' => env('GRAPH_ENDPOINT', 'https://graph.microsoft.com/v1.0'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /**
     * Our own bookkeeping, where the invoices of the admin panel are booked:
     * the username and the two security codes of the SOAP connection in
     * e-Boekhouden. Without them the panel shows nothing of e-Boekhouden.
     */
    'eboekhouden' => [
        'url' => env('EBOEKHOUDEN_URL', 'https://soap.e-boekhouden.nl/soap.asmx'),
        'username' => env('EBOEKHOUDEN_USERNAME'),
        'security_code_1' => env('EBOEKHOUDEN_SECURITY_CODE_1'),
        'security_code_2' => env('EBOEKHOUDEN_SECURITY_CODE_2'),
        'debtors_account' => env('EBOEKHOUDEN_DEBTORS_ACCOUNT', '1300'),
        'revenue_account' => env('EBOEKHOUDEN_REVENUE_ACCOUNT', '8000'),
    ],

    /**
     * The Android app, for /.well-known/assetlinks.json. The fingerprints are
     * the SHA-256 of every key that signs an installed copy, separated by
     * commas: the Play app signing key (Play Console, Test and release, App
     * integrity) and the debug key for development builds.
     */
    'android' => [
        'package_name' => env('ANDROID_PACKAGE_NAME', 'nl.lavoro.fsm'),
        'sha256_cert_fingerprints' => array_values(array_filter(array_map('trim', explode(',', env(
            'ANDROID_SHA256_CERT_FINGERPRINTS',
            '00:8D:D1:A2:9C:75:D0:C0:AD:AB:DC:50:44:03:26:C9:C7:2C:7B:D3:0B:63:FD:85:3C:B0:52:84:89:8A:9E:DE',
        ))))),
    ],

    'snelstart' => [
        'client_key' => env('SNELSTART_CLIENT_KEY'),
        'subscription_key' => env('SNELSTART_SUBSCRIPTION_KEY'),
        'auth_url' => env('SNELSTART_AUTH_URL', 'https://auth.snelstart.nl/b2b/token'),
        'api_base' => env('SNELSTART_API_BASE', 'https://b2bapi.snelstart.nl/v2'),
    ],

    /**
     * Route planning on the maps: a VROOM server. Either HeiGIT's, at
     * https://api.heigit.org/vroom/v0 with a free key from account.heigit.org,
     * or one of our own, which needs no key (scripts/routeplanner/setup.sh).
     * Without a url the maps offer no route planner.
     *
     * HeiGIT's old host, api.openrouteservice.org, answers the same key with a
     * tenth of the daily quota (50 routes instead of 500) until it is switched
     * off.
     */
    'routeplanner' => [
        'url' => env('ROUTEPLANNER_URL'),
        'key' => env('ROUTEPLANNER_KEY'),
    ],

];
