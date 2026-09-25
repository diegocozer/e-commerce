<?php

/*
| Shipping engine configuration (SHIPPING.md §14). Merged as config('shipping')
| by the module provider.
*/

return [
    'quote_ttl_minutes' => (int) env('SHIPPING_QUOTE_TTL_MINUTES', 30),
    'default_cubic_divisor' => 6000,
    'roll_margin_cm' => 10,
    'carrier_default_timeout_ms' => 5000,
    'carrier_total_budget_ms' => (int) env('SHIPPING_CARRIER_BUDGET_MS', 10000),
    'cutoff_time' => '14:00',
    'timezone' => 'America/Sao_Paulo',
    'config_cache_ttl_seconds' => 600,
    'postal_lookup' => [
        'driver' => env('SHIPPING_POSTAL_LOOKUP', 'viacep'), // viacep | fake
        'base_url' => env('VIACEP_BASE_URL', 'https://viacep.com.br/ws'),
        'timeout_seconds' => 3,
        'cache_days' => 30,
        'not_found_cache_hours' => 24,
    ],
    'rate_limit_per_minute' => 30,
];
