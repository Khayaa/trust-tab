<?php

use App\Support\MomoBaseUrl;
use App\Support\MomoCallbackHost;

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

    'momo' => [
        'base_url' => MomoBaseUrl::resolve((string) env('MOMO_BASE_URL'), (string) env('APP_URL')),
        'subscription_key' => env('MOMO_SUBSCRIPTION_KEY'),
        'api_user' => env('MOMO_API_USER'),
        'api_key' => env('MOMO_API_KEY'),
        'target_environment' => env('MOMO_TARGET_ENVIRONMENT', 'sandbox'),

        /**
         * Settlement currency sent to MoMo. Sandbox only accepts EUR;
         * mtnsouthafrica only accepts ZAR. This is deliberately separate
         * from the currency the UI displays.
         */
        'currency' => env('MOMO_CURRENCY', 'EUR'),

        /**
         * Must be a hostname that matches the one registered against the API
         * user, otherwise MoMo rejects the request with INVALID_CALLBACK_URL_HOST.
         * Cloud URL fields often store a full URL; the resolver strips the scheme.
         */
        'callback_host' => MomoCallbackHost::resolve(env('MOMO_CALLBACK_HOST')),

        'demo_mode' => env('MOMO_DEMO_MODE', false),
    ],

];
