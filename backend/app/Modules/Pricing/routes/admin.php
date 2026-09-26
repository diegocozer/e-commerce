<?php

/*
|--------------------------------------------------------------------------
| Pricing — admin panel (API.md §3.G.6 / §3.G.7, permissions §6.3)
|--------------------------------------------------------------------------
| Prefix: /api/v1/admin · route names: "admin.*"
*/

use App\Modules\Pricing\Http\Controllers\Admin\CouponController;
use App\Modules\Pricing\Http\Controllers\Admin\CustomerPriceController;
use App\Modules\Pricing\Http\Controllers\Admin\PriceListController;
use App\Modules\Pricing\Http\Controllers\Admin\PriceTierController;
use App\Modules\Pricing\Http\Controllers\Admin\PromotionController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:products.view,admin')->group(function (): void {
    Route::get('/variants/{variantId}/price-tiers', [PriceTierController::class, 'show'])->whereNumber('variantId')->name('variants.price-tiers.show');
    Route::get('/price-lists', [PriceListController::class, 'index'])->name('price-lists.index');
    Route::get('/price-lists/{id}', [PriceListController::class, 'show'])->whereNumber('id')->name('price-lists.show');
    Route::get('/price-lists/{id}/tiers', [PriceListController::class, 'tiers'])->whereNumber('id')->name('price-lists.tiers');
    Route::get('/promotions', [PromotionController::class, 'index'])->name('promotions.index');
    Route::get('/promotions/{id}', [PromotionController::class, 'show'])->whereNumber('id')->name('promotions.show');
    Route::post('/promotions/{id}/preview', [PromotionController::class, 'preview'])->whereNumber('id')->name('promotions.preview');
});

// Base tiers need prices.manage, price-list tiers need pricing.manage (checked in the controller).
Route::put('/variants/{variantId}/price-tiers', [PriceTierController::class, 'update'])->whereNumber('variantId')
    ->middleware('permission:prices.manage|pricing.manage,admin')->name('variants.price-tiers.update');

Route::middleware('permission:pricing.manage,admin')->group(function (): void {
    Route::post('/price-lists', [PriceListController::class, 'store'])->name('price-lists.store');
    Route::patch('/price-lists/{id}', [PriceListController::class, 'update'])->whereNumber('id')->name('price-lists.update');
    Route::delete('/price-lists/{id}', [PriceListController::class, 'destroy'])->whereNumber('id')->name('price-lists.destroy');
    Route::post('/customer-prices', [CustomerPriceController::class, 'store'])->name('customer-prices.store');
    Route::patch('/customer-prices/{id}', [CustomerPriceController::class, 'update'])->whereNumber('id')->name('customer-prices.update');
    Route::delete('/customer-prices/{id}', [CustomerPriceController::class, 'destroy'])->whereNumber('id')->name('customer-prices.destroy');
});
Route::get('/customer-prices', [CustomerPriceController::class, 'index'])->middleware('permission:customers.view,admin')->name('customer-prices.index');

Route::middleware('permission:promotions.manage,admin')->group(function (): void {
    Route::post('/promotions', [PromotionController::class, 'store'])->name('promotions.store');
    Route::patch('/promotions/{id}', [PromotionController::class, 'update'])->whereNumber('id')->name('promotions.update');
    Route::delete('/promotions/{id}', [PromotionController::class, 'destroy'])->whereNumber('id')->name('promotions.destroy');
});

Route::middleware('permission:coupons.manage|promotions.manage,admin')->group(function (): void {
    Route::get('/coupons', [CouponController::class, 'index'])->name('coupons.index');
    Route::post('/coupons', [CouponController::class, 'store'])->name('coupons.store');
    Route::post('/coupons/generate-code', [CouponController::class, 'generateCode'])->name('coupons.generate-code');
    Route::get('/coupons/{id}', [CouponController::class, 'show'])->whereNumber('id')->name('coupons.show');
    Route::patch('/coupons/{id}', [CouponController::class, 'update'])->whereNumber('id')->name('coupons.update');
    Route::delete('/coupons/{id}', [CouponController::class, 'destroy'])->whereNumber('id')->name('coupons.destroy');
    Route::get('/coupons/{id}/redemptions', [CouponController::class, 'redemptions'])->whereNumber('id')->name('coupons.redemptions');
});
