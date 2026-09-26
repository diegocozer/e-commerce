<?php

use App\Modules\Orders\Http\Controllers\Customer\CancellationRequestController;
use App\Modules\Orders\Http\Controllers\Customer\OrderCancellationController;
use App\Modules\Orders\Http\Controllers\Customer\OrderController;
use App\Modules\Orders\Http\Controllers\Customer\OrderPaymentController;
use App\Modules\Orders\Http\Controllers\Customer\OrderStatusController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Orders — authenticated customer area
|--------------------------------------------------------------------------
| Prefix: /api/v1/me · route names: "customer.*"
| middleware: api, auth:customer, throttle:customer.
| Orders are addressed by uuid and always resolved from the authenticated
| customer (404 for others — SECURITY.md §5).
*/

Route::get('orders', [OrderController::class, 'index'])->name('orders.index');

Route::prefix('orders/{uuid}')->whereUuid('uuid')->group(function (): void {
    Route::get('/', [OrderController::class, 'show'])->name('orders.show');
    Route::get('status', [OrderStatusController::class, 'show'])->name('orders.status');
    Route::post('cancel', [OrderCancellationController::class, 'store'])->name('orders.cancel');
    Route::post('cancellation-request', [CancellationRequestController::class, 'store'])->name('orders.cancellation-request');
    Route::post('payment', [OrderPaymentController::class, 'store'])->middleware('throttle:checkout')->name('orders.payment');
});
