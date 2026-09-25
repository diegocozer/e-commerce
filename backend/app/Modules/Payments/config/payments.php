<?php

/*
| Payment gateway configuration (ADR-010). Merged as config('payments') by the
| module provider. Secrets only from the environment.
*/

return [
    'driver' => env('PAYMENTS_DRIVER', 'sandbox'), // sandbox | mercadopago

    'drivers' => [
        'sandbox' => [
            'webhook_secret' => env('SANDBOX_WEBHOOK_SECRET'),
        ],
        'mercadopago' => [
            'base_url' => env('MERCADOPAGO_BASE_URL', 'https://api.mercadopago.com'),
            'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
            'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
            'timeout_seconds' => 10,
        ],
    ],
];
