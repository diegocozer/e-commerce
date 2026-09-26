<?php

use App\Modules\Shipping\Http\Controllers\Admin\CarrierConnectionTestController;
use App\Modules\Shipping\Http\Controllers\Admin\CarrierController;
use App\Modules\Shipping\Http\Controllers\Admin\CarrierDriverController;
use App\Modules\Shipping\Http\Controllers\Admin\ShippingMethodController;
use App\Modules\Shipping\Http\Controllers\Admin\ShippingQuoteController;
use App\Modules\Shipping\Http\Controllers\Admin\ShippingRuleController;
use App\Modules\Shipping\Http\Controllers\Admin\ShippingZoneController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Shipping — admin panel (prefix /api/v1/admin, names admin.*)
|--------------------------------------------------------------------------
| Every route requires shipping.manage (API.md §6.3). The simulator route
| (POST shipping/simulate) is registered by the Cart module.
*/

Route::prefix('shipping')->name('shipping.')->middleware('permission:shipping.manage,admin')->group(function (): void {
    Route::get('carriers/drivers', [CarrierDriverController::class, 'index'])->name('carriers.drivers');
    Route::post('carriers/{carrier}/test', [CarrierConnectionTestController::class, 'store'])->middleware('throttle:admin-heavy')->name('carriers.test');
    Route::apiResource('carriers', CarrierController::class)->parameters(['carriers' => 'carrier']);

    Route::put('methods/reorder', [ShippingMethodController::class, 'reorder'])->name('methods.reorder');
    Route::apiResource('methods', ShippingMethodController::class)->parameters(['methods' => 'method']);

    Route::post('zones/{zone}/test', [ShippingZoneController::class, 'test'])->name('zones.test');
    Route::apiResource('zones', ShippingZoneController::class)->parameters(['zones' => 'zone']);
    Route::get('cities', [ShippingZoneController::class, 'cities'])->name('cities.index');

    Route::post('rules/reorder', [ShippingRuleController::class, 'reorder'])->name('rules.reorder');
    Route::post('rules/{rule}/duplicate', [ShippingRuleController::class, 'duplicate'])->name('rules.duplicate');
    Route::apiResource('rules', ShippingRuleController::class)->parameters(['rules' => 'rule']);

    Route::get('quotes/{uuid}', [ShippingQuoteController::class, 'show'])->name('quotes.show');
});
