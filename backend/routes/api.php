<?php

use App\Shared\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
| Only infrastructure routes live here. Feature routes belong to the modules:
| app/Modules/<Module>/routes/{store,customer,admin_guest,admin,webhooks,web}.php
*/

Route::middleware('throttle:health')->group(function (): void {
    Route::get('/health', [HealthController::class, 'ready'])->name('health');
    Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
});
