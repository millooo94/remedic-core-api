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

    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'sms_from' => env('TWILIO_SMS_FROM', 'REMEDIC'),
        'default_country_code' => env('TWILIO_DEFAULT_COUNTRY_CODE', '+39'),
    ],

    'miodottore' => [
        'login_url' => env('MIODOTTORE_LOGIN_URL'),
        'verify_url' => env('MIODOTTORE_VERIFY_URL'),
        'username' => env('MIODOTTORE_USERNAME'),
        'password' => env('MIODOTTORE_PASSWORD'),
        'storage_state_path' => env('MIODOTTORE_STORAGE_STATE_PATH', 'miodottore/storage-state.json'),
        'access_timeout_ms' => (int) env('MIODOTTORE_ACCESS_TIMEOUT_MS', 600000),
        'access_slowmo_ms' => (int) env('MIODOTTORE_ACCESS_SLOWMO_MS', 150),
        'chromium_path' => env('MIODOTTORE_CHROMIUM_PATH'),
        'debug_headless' => filter_var(env('MIODOTTORE_DEBUG_HEADLESS', false), FILTER_VALIDATE_BOOL),
        'debug_timeout_ms' => (int) env('MIODOTTORE_DEBUG_TIMEOUT_MS', 90000),
        'debug_slowmo_ms' => (int) env('MIODOTTORE_DEBUG_SLOWMO_MS', 150),
        'debug_chromium_path' => env('MIODOTTORE_DEBUG_CHROMIUM_PATH'),
    ],

    'whatsapp_puppeteer' => [
        'base_url' => env('WHATSAPP_PUPPETEER_BASE_URL', 'http://127.0.0.1:3101'),
        'token' => env('WHATSAPP_PUPPETEER_TOKEN'),
        'timeout_seconds' => (int) env('WHATSAPP_PUPPETEER_TIMEOUT_SECONDS', 15),
        'node_binary' => env('WHATSAPP_PUPPETEER_NODE_BINARY', 'node'),
        'connector_workdir' => env('WHATSAPP_PUPPETEER_WORKDIR', base_path('whatsapp-connector')),
        'startup_wait_ms' => (int) env('WHATSAPP_PUPPETEER_STARTUP_WAIT_MS', 2000),
        'launch_cooldown_seconds' => (int) env('WHATSAPP_PUPPETEER_LAUNCH_COOLDOWN_SECONDS', 20),
        'disconnect_notification_to' => env('WHATSAPP_PUPPETEER_DISCONNECT_NOTIFICATION_TO', 'humancaretelemedicine@gmail.com'),
    ],

    'geocoding' => [
        'provider_url' => env('GEOCODING_PROVIDER_URL', 'https://nominatim.openstreetmap.org/search'),
        'user_agent' => env('GEOCODING_USER_AGENT', 'RemedicCore/1.0 (+https://core.remedic.it)'),
        'timeout_seconds' => (int) env('GEOCODING_TIMEOUT_SECONDS', 8),
        'remedic_address' => env('MARKETING_REMEDIC_ADDRESS', 'Via Salvatore Vigo 97/H, Acireale, 95024'),
        'remedic_lat' => env('MARKETING_REMEDIC_LAT', 37.6108),
        'remedic_lng' => env('MARKETING_REMEDIC_LNG', 15.1659),
    ],

];
