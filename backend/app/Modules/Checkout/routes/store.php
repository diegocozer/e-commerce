<?php

/*
|--------------------------------------------------------------------------
| Checkout — POST /checkout and /checkout/preview (API.md §3.E)
|--------------------------------------------------------------------------
| Prefix: /api/v1 · middleware: api + auth:customer.
*/

use App\Modules\Checkout\Http\Controllers\Store\CheckoutController;
use Illuminate\Support\Facades\Route;

Route::prefix('checkout')->name('checkout.')->middleware('auth:customer')->controller(CheckoutController::class)->group(function (): void {
    Route::post('preview', 'preview')->middleware('throttle:customer')->name('preview');
    Route::post('/', 'store')->middleware('throttle:checkout')->name('store');
});
