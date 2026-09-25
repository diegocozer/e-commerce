<?php

/*
|--------------------------------------------------------------------------
| Cart — public storefront API (API.md §3.B)
|--------------------------------------------------------------------------
| Prefix: /api/v1 · middleware: api · customer guard optional + X-Cart-Token.
*/

use App\Modules\Cart\Http\Controllers\Store\CartController;
use Illuminate\Support\Facades\Route;

Route::prefix('cart')->name('cart.')->controller(CartController::class)->group(function (): void {
    Route::middleware('throttle:cart')->group(function (): void {
        Route::get('/', 'show')->name('show');
        Route::delete('/', 'clear')->name('clear');
        Route::post('items', 'addItem')->name('items.store');
        Route::patch('items/{item}', 'updateItem')->whereNumber('item')->name('items.update');
        Route::delete('items/{item}', 'removeItem')->whereNumber('item')->name('items.destroy');
        Route::post('acknowledge-prices', 'acknowledgePrices')->name('acknowledge-prices');
        Route::delete('coupon', 'removeCoupon')->name('coupon.destroy');
    });
    Route::put('coupon', 'applyCoupon')->middleware('throttle:coupon')->name('coupon.update');
    Route::post('shipping-quote', 'shippingQuote')->middleware('throttle:shipping-quote')->name('shipping-quote');
});
