<?php

use App\Modules\Notifications\Http\Controllers\Admin\NotificationController;
use Illuminate\Support\Facades\Route;

/*
| Notifications — admin panel (/api/v1/admin, names admin.*), permission dashboard.view.
*/

Route::middleware('permission:dashboard.view,admin')->group(function (): void {
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read', [NotificationController::class, 'markRead'])->name('notifications.read');
});
