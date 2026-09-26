<?php

/*
|--------------------------------------------------------------------------
| Checkout — authenticated customer area
|--------------------------------------------------------------------------
| Prefix: /api/v1/me · route names: "customer.*"
| middleware: api, auth:customer, throttle:customer.
*/

use App\Modules\Checkout\Http\Controllers\Customer\ReorderController;
use App\Modules\Checkout\Http\Controllers\Customer\ReorderSuggestionController;
use Illuminate\Support\Facades\Route;

Route::post('orders/{uuid}/reorder', ReorderController::class)
    ->whereUuid('uuid')->middleware('throttle:cart')->name('orders.reorder');

Route::get('reorder-suggestions', [ReorderSuggestionController::class, 'index'])->name('reorder-suggestions');
