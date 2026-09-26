<?php

use App\Modules\Shipping\Http\Controllers\Store\PostalCodeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Shipping — public storefront API
|--------------------------------------------------------------------------
| Prefix: /api/v1 · no automatic name prefix · middleware: api.
| POST /shipping/quote (product estimate) and POST /cart/shipping-quote belong to
| the Cart module (IMPLEMENTATION_PLAN.md §4.5) and call Shipping\Contracts.
*/

Route::get('postal-codes/{cep}', [PostalCodeController::class, 'show'])
    ->middleware('throttle:postal-code')
    ->name('store.postal-codes.show');
