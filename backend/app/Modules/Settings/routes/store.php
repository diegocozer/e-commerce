<?php

/*
|--------------------------------------------------------------------------
| Settings — public storefront API (API.md §3.A)
|--------------------------------------------------------------------------
*/

use App\Modules\Settings\Http\Controllers\Store\PageController;
use App\Modules\Settings\Http\Controllers\Store\PublicSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('settings/public', [PublicSettingsController::class, 'show'])->middleware('throttle:catalog')->name('store.settings.public');
Route::get('pages/{slug}', [PageController::class, 'show'])->middleware('throttle:catalog')->name('store.pages.show');
