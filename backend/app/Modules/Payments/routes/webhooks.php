<?php

use App\Modules\Payments\Http\Controllers\Webhook\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Payments — payment gateway webhooks
|--------------------------------------------------------------------------
| Prefix: /api/v1/webhooks · route names: "webhooks.*"
| middleware group 'webhook': no session, no CSRF, throttle:webhooks. The HMAC
| signature is verified before anything else. `sandbox` is not accepted in
| production (404).
*/

Route::post('{provider}', [PaymentWebhookController::class, 'handle'])
    ->whereIn('provider', app()->isProduction() ? ['mercadopago'] : ['mercadopago', 'sandbox'])
    ->name('payments.handle');
