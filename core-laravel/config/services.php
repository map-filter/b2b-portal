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

    'rabbitmq' => [
        'host' => env('RABBITMQ_HOST', 'rabbitmq'),
        'port' => env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
    ],

    'bitrix24' => [
        'webhook' => env('B24_WEBHOOK_URL'),
        'stages' => [
            'new' => env('B24_STAGE_NEW', 'NEW'),
            'confirmed' => env('B24_STAGE_CONFIRMED', 'PREPARATION'),
            'assembling' => env('B24_STAGE_ASSEMBLING', 'EXECUTING'),
            'shipped' => env('B24_STAGE_SHIPPED', 'FINAL_INVOICE'),
            'completed' => env('B24_STAGE_COMPLETED', 'WON'),
            'cancelled' => env('B24_STAGE_CANCELLED', 'LOSE'),
        ],
    ],

    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
    ],

    'integration' => [
        'token' => env('INTEGRATION_TOKEN'),
    ],

];
