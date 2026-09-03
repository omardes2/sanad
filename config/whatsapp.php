<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Cloud API — central configuration
    |--------------------------------------------------------------------------
    |
    | Text transport for SANAD (Sprint 0D). NO real credentials live here or in
    | the repository. When `enabled` is true but required settings are missing,
    | the integration fails closed (see App\Support\WhatsApp\WhatsAppConfig).
    |
    */

    'enabled' => filter_var(env('WHATSAPP_ENABLED', false), FILTER_VALIDATE_BOOL),

    'graph_base_url' => env('WHATSAPP_GRAPH_BASE_URL') ?: 'https://graph.facebook.com',

    'graph_version' => env('WHATSAPP_GRAPH_VERSION') ?: 'v21.0',

    // Secrets — resolved from the environment, never committed.
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
    'app_secret' => env('WHATSAPP_APP_SECRET'),
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),

    // Identifiers of the configured WhatsApp Business phone number / account.
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),

    'request_timeout' => (int) (env('WHATSAPP_REQUEST_TIMEOUT') ?: 10),

];
