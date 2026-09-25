<?php

use App\Modules\Payments\Http\Controllers\Dev\SandboxPaymentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Payments — development helpers (sandbox approve/fail)
|--------------------------------------------------------------------------
| Prefix: /api/v1/dev · route names: "dev.*"
| Registered ONLY when APP_ENV is local or testing (ModuleServiceProvider) and
| the payments driver is `sandbox` (API.md §3.F).
*/

if (config('payments.driver') === 'sandbox') {
    Route::middleware(['auth:customer', 'throttle:customer'])->whereUuid('orderUuid')->group(function (): void {
        Route::post('payments/{orderUuid}/approve', [SandboxPaymentController::class, 'approve'])->name('payments.approve');
        Route::post('payments/{orderUuid}/fail', [SandboxPaymentController::class, 'fail'])->name('payments.fail');
    });
}
