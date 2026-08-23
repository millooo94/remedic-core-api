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

    'geocoding' => [
        'provider_url' => env('GEOCODING_PROVIDER_URL', 'https://nominatim.openstreetmap.org/search'),
        'user_agent' => env('GEOCODING_USER_AGENT', 'RemedicCore/1.0 (+https://core.remedic.it)'),
        'timeout_seconds' => (int) env('GEOCODING_TIMEOUT_SECONDS', 8),
        'remedic_address' => env('MARKETING_REMEDIC_ADDRESS', 'Via Salvatore Vigo 97/H, Acireale, 95024'),
        'remedic_lat' => env('MARKETING_REMEDIC_LAT', 37.6108),
        'remedic_lng' => env('MARKETING_REMEDIC_LNG', 15.1659),
    ],

];
