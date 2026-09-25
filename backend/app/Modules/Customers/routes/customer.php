<?php

/*
|--------------------------------------------------------------------------
| Customers — authenticated customer area (API.md §3.D)
|--------------------------------------------------------------------------
| Prefix: /api/v1/me · names customer.* · middleware api, auth:customer, throttle:customer
*/

use App\Modules\Customers\Http\Controllers\Customer\AddressController;
use App\Modules\Customers\Http\Controllers\Customer\CompanyController;
use App\Modules\Customers\Http\Controllers\Customer\DefaultAddressController;
use App\Modules\Customers\Http\Controllers\Customer\PasswordController;
use App\Modules\Customers\Http\Controllers\Customer\ProfileController;
use App\Modules\Customers\Http\Controllers\Customer\TermsAcceptanceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ProfileController::class, 'show'])->name('profile.show');
Route::patch('/', [ProfileController::class, 'update'])->name('profile.update');
Route::put('password', [PasswordController::class, 'update'])->name('password.update');
Route::patch('company', [CompanyController::class, 'update'])->name('company.update');
Route::post('terms-acceptance', [TermsAcceptanceController::class, 'store'])->name('terms.store');

Route::get('addresses', [AddressController::class, 'index'])->name('addresses.index');
Route::post('addresses', [AddressController::class, 'store'])->name('addresses.store');
Route::get('addresses/{address}', [AddressController::class, 'show'])->whereUuid('address')->name('addresses.show');
Route::patch('addresses/{address}', [AddressController::class, 'update'])->whereUuid('address')->name('addresses.update');
Route::delete('addresses/{address}', [AddressController::class, 'destroy'])->whereUuid('address')->name('addresses.destroy');
Route::post('addresses/{address}/default', [DefaultAddressController::class, 'store'])->whereUuid('address')->name('addresses.default');
