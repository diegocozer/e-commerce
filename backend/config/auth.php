<?php

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\AdminUser;

/*
|--------------------------------------------------------------------------
| Authentication (ADR-006 / ADR-023 / SECURITY.md §3)
|--------------------------------------------------------------------------
|
| Two user types with separate tables, providers, guards and password
| brokers. Both guards are session based (Sanctum stateful SPA); there are
| no API tokens. There is intentionally no "web" guard nor "users" table.
|
*/

return [

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'customer'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'customers'),
    ],

    'guards' => [
        'customer' => [
            'driver' => 'session',
            'provider' => 'customers',
        ],

        'admin' => [
            'driver' => 'session',
            'provider' => 'admin_users',
        ],
    ],

    'providers' => [
        'customers' => [
            'driver' => 'eloquent',
            'model' => Customer::class,
        ],

        'admin_users' => [
            'driver' => 'eloquent',
            'model' => AdminUser::class,
        ],
    ],

    'passwords' => [
        'customers' => [
            'provider' => 'customers',
            'table' => 'customer_password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],

        'admin_users' => [
            'provider' => 'admin_users',
            'table' => 'admin_password_reset_tokens',
            'expire' => 30,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

    /*
    | Admin session limits (ADR-023): inactivity timeout and absolute lifetime,
    | enforced by App\Shared\Http\Middleware\EnsureAdminSessionIsFresh.
    */
    'admin_session' => [
        'idle_timeout_minutes' => (int) env('ADMIN_SESSION_IDLE_MINUTES', 30),
        'absolute_timeout_minutes' => (int) env('ADMIN_SESSION_ABSOLUTE_MINUTES', 480),
    ],

];
