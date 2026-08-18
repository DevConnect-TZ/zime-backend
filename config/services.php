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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID'),
    ],

    'sonicpesa' => [
        'base_url' => env('SONICPESA_BASE_URL', 'https://api.sonicpesa.com/api/v1'),
        'api_key' => env('SONICPESA_API_KEY'),
        'api_secret' => env('SONICPESA_API_SECRET'),
        'webhook_secret' => env('SONICPESA_WEBHOOK_SECRET', env('SONICPESA_API_SECRET')),
    ],

    'auth_tokens' => [
        'access_ttl' => (int) env('ACCESS_TOKEN_TTL', 900),
        'refresh_ttl' => (int) env('REFRESH_TOKEN_TTL', 60 * 60 * 24 * 30),
        'refresh_cookie' => env('REFRESH_COOKIE_NAME', 'zime_refresh'),
    ],

    'platform' => [
        'bootstrap_admin_email' => env('BOOTSTRAP_ADMIN_EMAIL'),
    ],

];
