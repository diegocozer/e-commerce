<?php

/*
|--------------------------------------------------------------------------
| Customers — public storefront API (customer authentication, API.md §3.C)
|--------------------------------------------------------------------------
| Prefix: /api/v1 · names auth.*
*/

use App\Modules\Customers\Http\Controllers\Store\Auth\EmailVerificationController;
use App\Modules\Customers\Http\Controllers\Store\Auth\NewPasswordController;
use App\Modules\Customers\Http\Controllers\Store\Auth\PasswordResetLinkController;
use App\Modules\Customers\Http\Controllers\Store\Auth\RegisterController;
use App\Modules\Customers\Http\Controllers\Store\Auth\SessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('register', [RegisterController::class, 'store'])->middleware('throttle:register')->name('register');
    Route::post('login', [SessionController::class, 'store'])->middleware('throttle:login')->name('login');
    Route::post('logout', [SessionController::class, 'destroy'])->middleware('auth:customer')->name('logout');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:password-reset')->name('password.email');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:password-reset')->name('password.reset');
    Route::post('email/verify', [EmailVerificationController::class, 'verify'])->middleware('throttle:password-reset')->name('verification.verify');
    Route::post('email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware(['auth:customer', 'throttle:password-reset'])->name('verification.send');
});
