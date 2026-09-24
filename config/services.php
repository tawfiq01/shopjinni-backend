<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'google_drive' => [
        'client_id' => env('GOOGLE_DRIVE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_DRIVE_REDIRECT_URI'),
    ],

    // Sign-in-with-Google (registration/login) — a separate redirect_uri
    // from google_drive since the callback path differs, but defaults to
    // the same Client ID/Secret so the existing Google Cloud OAuth client
    // can be reused: just add GOOGLE_LOGIN_REDIRECT_URI as an additional
    // authorized redirect URI on that same client, or set the LOGIN_*
    // vars to a separate client if preferred.
    'google_login' => [
        'client_id' => env('GOOGLE_LOGIN_CLIENT_ID', env('GOOGLE_DRIVE_CLIENT_ID')),
        'client_secret' => env('GOOGLE_LOGIN_CLIENT_SECRET', env('GOOGLE_DRIVE_CLIENT_SECRET')),
        'redirect_uri' => env('GOOGLE_LOGIN_REDIRECT_URI'),
    ],

    // Where the Google sign-in callback hands the browser back to. Fixed
    // (not caller-supplied) to avoid an open-redirect — update this per
    // environment rather than accepting the target from the request.
    'frontend' => [
        'url' => env('FRONTEND_URL', 'http://localhost:5173'),
    ],

];
