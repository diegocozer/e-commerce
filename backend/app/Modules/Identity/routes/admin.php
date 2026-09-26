<?php

/*
|--------------------------------------------------------------------------
| Identity — admin panel (API.md §3.G.1 / §3.G.12)
|--------------------------------------------------------------------------
| Prefix: /api/v1/admin · names admin.* · middleware api, auth:admin, admin.fresh, throttle:admin
| Every route declares a permission, except admin.me* (authentication only — API.md §6.3).
*/

use App\Modules\Identity\Http\Controllers\Admin\AdminUserController;
use App\Modules\Identity\Http\Controllers\Admin\AdminUserPasswordResetController;
use App\Modules\Identity\Http\Controllers\Admin\AdminUserStatusController;
use App\Modules\Identity\Http\Controllers\Admin\MeController;
use App\Modules\Identity\Http\Controllers\Admin\MePasswordController;
use App\Modules\Identity\Http\Controllers\Admin\PermissionController;
use App\Modules\Identity\Http\Controllers\Admin\RoleController;
use Illuminate\Support\Facades\Route;

Route::get('me', [MeController::class, 'show'])->name('me.show');
Route::put('me/password', [MePasswordController::class, 'update'])->name('me.password.update');

Route::middleware('permission:admin_users.manage,admin')->group(function (): void {
    Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
    Route::post('users', [AdminUserController::class, 'store'])->name('users.store');
    Route::get('users/{user}', [AdminUserController::class, 'show'])->whereNumber('user')->name('users.show');
    Route::patch('users/{user}', [AdminUserController::class, 'update'])->whereNumber('user')->name('users.update');
    Route::delete('users/{user}', [AdminUserController::class, 'destroy'])->whereNumber('user')->name('users.destroy');
    Route::post('users/{user}/activate', [AdminUserStatusController::class, 'activate'])->whereNumber('user')->name('users.activate');
    Route::post('users/{user}/deactivate', [AdminUserStatusController::class, 'deactivate'])->whereNumber('user')->name('users.deactivate');
    Route::post('users/{user}/password-reset', [AdminUserPasswordResetController::class, 'store'])->whereNumber('user')->name('users.password-reset');

    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
    Route::get('roles/{role}', [RoleController::class, 'show'])->whereNumber('role')->name('roles.show');
    Route::patch('roles/{role}', [RoleController::class, 'update'])->whereNumber('role')->name('roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->whereNumber('role')->name('roles.destroy');

    Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
});
