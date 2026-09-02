<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | SANAD Application Settings
    |--------------------------------------------------------------------------
    |
    | Central, project-specific configuration for SANAD. Keeping these here
    | (instead of scattered magic values) makes the codebase ready to grow
    | into a multi-user SaaS, where per-user overrides can layer on top of
    | these application-wide defaults.
    |
    */

    // Human-friendly product name (kept in sync with APP_NAME).
    'name' => env('APP_NAME', 'SANAD'),

    /*
    |--------------------------------------------------------------------------
    | Default User Timezone
    |--------------------------------------------------------------------------
    |
    | Internal storage is ALWAYS UTC (see config/app.php). This value is the
    | default timezone used when DISPLAYING times to a user who has not yet
    | chosen their own. In a multi-user setup this becomes the per-user default.
    |
    */
    'default_timezone' => env('DEFAULT_USER_TIMEZONE', 'Asia/Hebron'),

    /*
    |--------------------------------------------------------------------------
    | Default Currency
    |--------------------------------------------------------------------------
    |
    | ISO 4217 currency code used as the default for money-related features
    | (expenses, invoices) until a user selects their own.
    |
    */
    'default_currency' => env('DEFAULT_CURRENCY', 'ILS'),

    /*
    |--------------------------------------------------------------------------
    | Locales
    |--------------------------------------------------------------------------
    |
    | Supported UI locales. Arabic is primary (RTL); English is planned.
    |
    */
    'locales' => [
        'ar' => ['name' => 'العربية', 'dir' => 'rtl'],
        'en' => ['name' => 'English', 'dir' => 'ltr'],
    ],

];
