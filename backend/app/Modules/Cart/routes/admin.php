<?php

use App\Modules\Cart\Http\Controllers\Admin\ShippingSimulatorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Cart — admin panel (prefix /api/v1/admin, names admin.*)
|--------------------------------------------------------------------------
| Shipping simulator: owned by B-C (IMPLEMENTATION_PLAN.md §2), lives in Cart
| because the `items` mode needs Catalog + Pricing (API.md §5.2 note ²).
*/

Route::post('shipping/simulate', [ShippingSimulatorController::class, 'store'])
    ->middleware(['permission:shipping.manage,admin', 'throttle:admin-heavy'])
    ->name('shipping.simulate');
