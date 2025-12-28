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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    // Firebase Cloud Messaging
    'fcm' => [
        'server_key' => env('FCM_SERVER_KEY'),
    ],

    // WhatsApp Business API
    'whatsapp' => [
        'api_url' => env('WHATSAPP_API_URL', 'https://graph.facebook.com/v18.0'),
        'api_key' => env('WHATSAPP_API_KEY'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    ],

    // SMS Service (Zenvia)
    'sms' => [
        'api_url' => env('SMS_API_URL', 'https://api.zenvia.com/v2'),
        'api_key' => env('SMS_API_KEY'),
        'from_number' => env('SMS_FROM_NUMBER', '2Pets'),
    ],

    // Mercado Pago
    'mercadopago' => [
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
        'public_key' => env('MERCADOPAGO_PUBLIC_KEY'),
    ],

    // Daily.co Video API
    'daily' => [
        'api_key' => env('DAILY_API_KEY'),
        'domain' => env('DAILY_DOMAIN', '2pets.daily.co'),
    ],

    // Billing Gateway (Stripe/Asaas)
    'billing' => [
        'gateway_key' => env('BILLING_GATEWAY_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
