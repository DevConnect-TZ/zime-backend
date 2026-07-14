<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The frontend authenticates with a Bearer access token and relies on an
    | httpOnly refresh cookie, so credentials must be allowed and the allowed
    | origins must be an explicit allow-list (wildcards are not permitted when
    | credentials are enabled).
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', (string) env('FRONTEND_URLS', 'http://localhost:3000'))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Access-Token'],

    'max_age' => 0,

    'supports_credentials' => true,

];
