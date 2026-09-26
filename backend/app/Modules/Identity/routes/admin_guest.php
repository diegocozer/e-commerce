<?php

/*
|--------------------------------------------------------------------------
| Identity — public admin endpoints (API.md §3.G.1)
|--------------------------------------------------------------------------
| Prefix: /api/v1/admin · names admin.* · middleware api
*/

use App\Modules\Identity\Http\Controllers\Admin\Auth\NewPasswordController;
use App\Modules\Identity\Http\Controllers\Admin\Auth\PasswordResetLinkController;
use App\Modules\Identity\Http\Controllers\Admin\Auth\SessionController;
use App\Shared\Http\Middleware\EnsureAdminSessionIsFresh;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('login', [SessionController::class, 'store'])->middleware('throttle:login')->name('login');
    Route::post('logout', [SessionController::class, 'destroy'])->middleware(['auth:admin', EnsureAdminSessionIsFresh::class])->name('logout');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:password-reset')->name('password.email');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:password-reset')->name('password.reset');
});
