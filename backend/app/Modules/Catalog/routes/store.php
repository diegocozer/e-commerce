<?php

/*
|--------------------------------------------------------------------------
| Catalog — public storefront API
|--------------------------------------------------------------------------
| Prefix: /api/v1 · no automatic name prefix
| middleware: api. Name routes explicitly (store.*) and add throttle:<limiter> per route (API.md §1.1).
*/

use App\Modules\Catalog\Http\Controllers\Store\BrandController;
use App\Modules\Catalog\Http\Controllers\Store\CategoryController;
use App\Modules\Catalog\Http\Controllers\Store\PricePreviewController;
use App\Modules\Catalog\Http\Controllers\Store\ProductAutocompleteController;
use App\Modules\Catalog\Http\Controllers\Store\ProductController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:catalog')->group(function (): void {
    Route::get('/categories', [CategoryController::class, 'index'])->name('store.categories.index');
    Route::get('/categories/{slug}', [CategoryController::class, 'show'])->name('store.categories.show');
    Route::get('/brands', [BrandController::class, 'index'])->name('store.brands.index');
    Route::get('/products/{slug}/related', [ProductController::class, 'related'])->name('store.products.related');
});

// `search` limiter when q is present (API.md §1.8), else `catalog`.
Route::get('/products', [ProductController::class, 'index'])
    ->middleware('throttle:catalog-products')
    ->name('store.products.index');
Route::get('/products/autocomplete', [ProductAutocompleteController::class, 'index'])
    ->middleware('throttle:search')->name('store.products.autocomplete');
Route::get('/products/{slug}', [ProductController::class, 'show'])
    ->middleware('throttle:catalog')->name('store.products.show');
Route::post('/products/{slug}/price-preview', [PricePreviewController::class, 'store'])
    ->middleware('throttle:price-preview')->name('store.products.price-preview');
