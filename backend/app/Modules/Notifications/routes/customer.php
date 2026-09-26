<?php

use App\Modules\Notifications\Http\Controllers\Customer\NotificationController;
use Illuminate\Support\Facades\Route;

/*
| Notifications — authenticated customer area (/api/v1/me, names customer.*)
*/

Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
Route::post('notifications/read', [NotificationController::class, 'markRead'])->name('notifications.read');
