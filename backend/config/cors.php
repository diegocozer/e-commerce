<?php

/*
|--------------------------------------------------------------------------
| CORS (SECURITY.md §7.2)
|--------------------------------------------------------------------------
|
| Production serves the SPAs and the API from the same origin, so CORS is
| not needed there (CORS_ALLOWED_ORIGINS empty). In other environments the
| allowed origins are an explicit, comma separated list. Never "*" together
| with credentials.
|
*/

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
)));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Content-Type',
        'X-XSRF-TOKEN',
        'X-Requested-With',
        'X-Cart-Token',
        'Idempotency-Key',
        'X-Request-Id',
    ],

    'exposed_headers' => ['X-Cart-Token', 'X-Request-Id', 'Retry-After'],

    'max_age' => 600,

    'supports_credentials' => true,

];
