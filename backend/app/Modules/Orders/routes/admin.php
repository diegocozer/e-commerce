<?php

use App\Modules\Orders\Http\Controllers\Admin\CancellationRequestController;
use App\Modules\Orders\Http\Controllers\Admin\OrderCancellationController;
use App\Modules\Orders\Http\Controllers\Admin\OrderController;
use App\Modules\Orders\Http\Controllers\Admin\OrderSensitiveDataController;
use App\Modules\Orders\Http\Controllers\Admin\OrderStatusCountController;
use App\Modules\Orders\Http\Controllers\Admin\OrderTransitionController;
use App\Modules\Orders\Http\Controllers\Admin\PaymentReconciliationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Orders — admin panel (API.md §3.G.9, permissions §6.3)
|--------------------------------------------------------------------------
| Prefix: /api/v1/admin · route names: "admin.*"
| middleware: api, auth:admin, admin.fresh, throttle:admin. Orders by integer id.
| Field/status-dependent permissions are re-checked in the controllers.
*/

Route::get('orders', [OrderController::class, 'index'])->middleware('permission:orders.view,admin')->name('orders.index');
Route::get('orders/status-counts', [OrderStatusCountController::class, 'show'])->middleware('permission:orders.view,admin')->name('orders.status-counts');

Route::prefix('orders/{order:id}')->whereNumber('order')->group(function (): void {
    Route::get('/', [OrderController::class, 'show'])->middleware('permission:orders.view,admin')->name('orders.show');
    Route::patch('/', [OrderController::class, 'update'])->middleware('permission:orders.notes|orders.fulfill,admin')->name('orders.update');
    Route::post('transitions', [OrderTransitionController::class, 'store'])->middleware('permission:orders.fulfill|orders.pickup,admin')->name('orders.transitions.store');
    Route::post('cancel', [OrderCancellationController::class, 'store'])->middleware('permission:orders.cancel_unpaid|orders.cancel_paid,admin')->name('orders.cancel');
    Route::post('cancellation-request/dismiss', [CancellationRequestController::class, 'dismiss'])->middleware('permission:orders.cancel_paid,admin')->name('orders.cancellation-request.dismiss');
    Route::post('reveal-document', [OrderSensitiveDataController::class, 'store'])->middleware('permission:customers.view_sensitive,admin')->name('orders.reveal-document');
    Route::post('payments/reconcile', [PaymentReconciliationController::class, 'store'])->middleware('permission:payments.reconcile,admin')->name('orders.payments.reconcile');
});
