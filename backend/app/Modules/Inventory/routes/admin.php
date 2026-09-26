<?php

/*
|--------------------------------------------------------------------------
| Inventory — admin panel (API.md §3.G.8, permissions §6.3)
|--------------------------------------------------------------------------
| Prefix: /api/v1/admin · route names: "admin.*"
*/

use App\Modules\Inventory\Http\Controllers\Admin\InventoryController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:inventory.view,admin')->group(function (): void {
    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::get('/inventory/movements', [InventoryController::class, 'movements'])->name('inventory.movements.all');
    Route::get('/inventory/{variantId}', [InventoryController::class, 'show'])->whereNumber('variantId')->name('inventory.show');
    Route::get('/inventory/{variantId}/movements', [InventoryController::class, 'movements'])->whereNumber('variantId')->name('inventory.movements.index');
});
Route::patch('/inventory/{variantId}', [InventoryController::class, 'update'])->whereNumber('variantId')
    ->middleware('permission:inventory.adjust,admin')->name('inventory.update');
Route::post('/inventory/{variantId}/adjustments', [InventoryController::class, 'adjustments'])->whereNumber('variantId')
    ->middleware('permission:inventory.adjust,admin')->name('inventory.adjustments.store');
Route::post('/inventory/{variantId}/entries', [InventoryController::class, 'entries'])->whereNumber('variantId')
    ->middleware('permission:inventory.move,admin')->name('inventory.entries.store');
