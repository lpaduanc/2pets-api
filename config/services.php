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
        'maps_api_key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    // Cache das respostas de geocoding (30 dias, limite dos termos do Google). Redis por
    // padrão; a suíte usa `array` (phpunit.xml) para um teste não herdar resposta de outro.
    // `providers`: liga/desliga cada serviço de mapas. A ORDEM aqui é a prioridade — o
    // primeiro ativo que responder ganha (ver `ChainGeocodingProvider`). Google ativo sem
    // `GOOGLE_MAPS_API_KEY` é pulado sozinho. A mesma lista vale para o autocomplete de
    // endereço (`address-search`): `google` liga o Places junto com o Geocoding; `photon` só
    // serve autocomplete e `nominatim` só geocoding (a política dele proíbe autocomplete).
    'geocoding' => [
        'cache_store' => env('GEOCODING_CACHE_STORE', 'redis'),
        'providers' => [
            'google' => ['enabled' => (bool) env('GEOCODING_GOOGLE_ENABLED', true)],
            'nominatim' => ['enabled' => (bool) env('GEOCODING_NOMINATIM_ENABLED', false)],
            'photon' => ['enabled' => (bool) env('GEOCODING_PHOTON_ENABLED', false)],
        ],
    ],

    // Photon (komoot) — autocomplete sobre o OpenStreetMap. Sem chave; User-Agent identificado
    // como no Nominatim (cortesia com a instância pública). Ver `PhotonSuggestionProvider`.
    'photon' => [
        'base_url' => env('PHOTON_BASE_URL', 'https://photon.komoot.io'),
        'user_agent' => env('PHOTON_USER_AGENT', env('NOMINATIM_USER_AGENT', '2pets/1.0 (contato@2pets.com.br)')),
    ],

    // Nominatim (OpenStreetMap). A política da instância pública exige User-Agent com contato
    // e no máximo 1 req/s — ver `NominatimGeocodingProvider`.
    'nominatim' => [
        'base_url' => env('NOMINATIM_BASE_URL', 'https://nominatim.openstreetmap.org'),
        'user_agent' => env('NOMINATIM_USER_AGENT', '2pets/1.0 (contato@2pets.com.br)'),
        'email' => env('NOMINATIM_EMAIL'),
        'min_interval_seconds' => (int) env('NOMINATIM_MIN_INTERVAL_SECONDS', 1),
    ],

    // CEP → endereço (busca por CEP e fallback de localização do tutor). Pública, sem chave.
    'viacep' => [
        'base_url' => env('VIACEP_BASE_URL', 'https://viacep.com.br'),
    ],

    // Stripe Payment Gateway
    // O fallback '' é obrigatório: StripeService tipa `private string $secretKey` e conta com
    // `isConfigured()` para operar sem credencial (dev, teste, ambiente sem Stripe). Sem o
    // default aqui, env() devolve null, o `config(..., '')` NÃO se aplica (a chave existe, só
    // vale null) e o construtor morre com TypeError — derrubando qualquer rota que resolva
    // BillingService.
    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY', ''),
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY', ''),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
    ],

    // Firebase Cloud Messaging (HTTP v1 API)
    // Legacy server_key is DEPRECATED by Google as of June 2024. Kept here only
    // so PushNotificationService can detect and warn legacy configurations.
    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'service_account_json_path' => env('FCM_SERVICE_ACCOUNT_JSON_PATH'),
        'service_account_json_base64' => env('FCM_SERVICE_ACCOUNT_JSON_BASE64'),
        'server_key' => env('FCM_SERVER_KEY'), // @deprecated — Legacy HTTP API was shut down 2024-06-20
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
        'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
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
