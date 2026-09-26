<?php

/*
| Payment gateway configuration (ADR-010). Merged as config('payments') by the
| module provider. Secrets only from the environment.
*/

return [
    'driver' => env('PAYMENTS_DRIVER', 'sandbox'), // sandbox | mercadopago

    'drivers' => [
        'sandbox' => [
            // Empty value in .env (as in .env.example) falls back to the dev secret; sandbox never runs in production.
            'webhook_secret' => env('SANDBOX_WEBHOOK_SECRET') ?: 'sandbox-secret-dev',
            'webhook_secret_previous' => env('SANDBOX_WEBHOOK_SECRET_PREVIOUS'),
        ],
        'mercadopago' => [
            'base_url' => env('MERCADOPAGO_BASE_URL', 'https://api.mercadopago.com'),
            'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
            'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
            'webhook_secret_previous' => env('MERCADOPAGO_WEBHOOK_SECRET_PREVIOUS'),
            'notification_url' => env('MERCADOPAGO_NOTIFICATION_URL'),
            'timeout_seconds' => 10,
        ],
    ],

    // Timeouts in seconds (ARCHITECTURE.md §7.1).
    'timeouts' => ['connect' => 3, 'create' => 10, 'get' => 5, 'refund' => 15],

    // Webhook signature tolerance (|now - ts|, seconds) and maximum body size.
    'webhook_tolerance_seconds' => 300,
    'webhook_max_body_bytes' => 65536,
];
