<?php

/*
|--------------------------------------------------------------------------
| Settings — admin panel (API.md §3.G.13)
|--------------------------------------------------------------------------
*/

use App\Modules\Settings\Http\Controllers\Admin\SettingController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:settings.manage,admin')->group(function (): void {
    Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
    Route::patch('settings', [SettingController::class, 'update'])->name('settings.update');
});
