<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The Flutter frontend is served from a different origin than this API
    | (e.g. frontend on https://shopjinne.com, API on https://app.shopjinne.com),
    | so the browser enforces CORS on every request between them. Without this
    | file, Laravel's CORS middleware has no paths configured and never adds
    | the Access-Control-Allow-Origin header, and every cross-origin call from
    | the frontend fails silently in the browser.
    |
    | Auth is Bearer-token based (Sanctum personal access tokens), not cookie
    | sessions, so supports_credentials stays false and allowed_origins can
    | safely be an explicit allowlist read from CORS_ALLOWED_ORIGINS.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:5173')))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
