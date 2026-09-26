<?php

/*
|--------------------------------------------------------------------------
| Catalog — admin panel (API.md §3.G.3–§3.G.5, permissions §6.3)
|--------------------------------------------------------------------------
| Prefix: /api/v1/admin · route names: "admin.*"
*/

use App\Modules\Catalog\Http\Controllers\Admin\BrandController;
use App\Modules\Catalog\Http\Controllers\Admin\CategoryController;
use App\Modules\Catalog\Http\Controllers\Admin\ProductController;
use App\Modules\Catalog\Http\Controllers\Admin\ProductImageController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:products.view,admin')->group(function (): void {
    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::get('/categories/{id}', [CategoryController::class, 'show'])->whereNumber('id')->name('categories.show');
    Route::get('/brands', [BrandController::class, 'index'])->name('brands.index');
    Route::get('/brands/{id}', [BrandController::class, 'show'])->whereNumber('id')->name('brands.show');
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/slug-availability', [ProductController::class, 'slugAvailability'])->name('products.slug-availability');
    Route::get('/products/{id}', [ProductController::class, 'show'])->whereNumber('id')->name('products.show');
    Route::get('/variants', [ProductController::class, 'variantPicker'])->name('variants.index');
    Route::get('/variants/sku-availability', [ProductController::class, 'skuAvailability'])->name('variants.sku-availability');
});

Route::middleware('permission:products.manage,admin')->group(function (): void {
    Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::post('/categories/reorder', [CategoryController::class, 'reorder'])->name('categories.reorder');
    Route::patch('/categories/{id}', [CategoryController::class, 'update'])->whereNumber('id')->name('categories.update');
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy'])->whereNumber('id')->name('categories.destroy');
    Route::post('/categories/{id}/image', [CategoryController::class, 'storeImage'])->whereNumber('id')->middleware('throttle:uploads')->name('categories.image.store');
    Route::delete('/categories/{id}/image', [CategoryController::class, 'destroyImage'])->whereNumber('id')->name('categories.image.destroy');

    Route::post('/brands', [BrandController::class, 'store'])->name('brands.store');
    Route::patch('/brands/{id}', [BrandController::class, 'update'])->whereNumber('id')->name('brands.update');
    Route::delete('/brands/{id}', [BrandController::class, 'destroy'])->whereNumber('id')->name('brands.destroy');
    Route::post('/brands/{id}/logo', [BrandController::class, 'storeLogo'])->whereNumber('id')->middleware('throttle:uploads')->name('brands.logo.store');
    Route::delete('/brands/{id}/logo', [BrandController::class, 'destroyLogo'])->whereNumber('id')->name('brands.logo.destroy');

    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::post('/products/bulk', [ProductController::class, 'bulk'])->name('products.bulk');
    Route::patch('/products/{id}', [ProductController::class, 'update'])->whereNumber('id')->name('products.update');
    Route::delete('/products/{id}', [ProductController::class, 'destroy'])->whereNumber('id')->name('products.destroy');
    Route::delete('/products/{id}/variants/{variantId}', [ProductController::class, 'destroyVariant'])->whereNumber(['id', 'variantId'])->name('products.variants.destroy');

    Route::post('/products/{id}/images', [ProductImageController::class, 'store'])->whereNumber('id')->middleware('throttle:uploads')->name('products.images.store');
    Route::post('/products/{id}/images/reorder', [ProductImageController::class, 'reorder'])->whereNumber('id')->name('products.images.reorder');
    Route::patch('/products/{id}/images/{imageId}', [ProductImageController::class, 'update'])->whereNumber(['id', 'imageId'])->name('products.images.update');
    Route::delete('/products/{id}/images/{imageId}', [ProductImageController::class, 'destroy'])->whereNumber(['id', 'imageId'])->name('products.images.destroy');
});
