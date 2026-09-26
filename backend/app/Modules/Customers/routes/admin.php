<?php

/*
|--------------------------------------------------------------------------
| Customers — admin panel (API.md §3.G.10)
|--------------------------------------------------------------------------
| Field-level permissions of PATCH endpoints are checked in the Form Requests (API.md §6.3).
*/

use App\Modules\Customers\Http\Controllers\Admin\CompanyController;
use App\Modules\Customers\Http\Controllers\Admin\CustomerAnonymizationController;
use App\Modules\Customers\Http\Controllers\Admin\CustomerBlockController;
use App\Modules\Customers\Http\Controllers\Admin\CustomerController;
use App\Modules\Customers\Http\Controllers\Admin\CustomerPasswordResetController;
use App\Modules\Customers\Http\Controllers\Admin\CustomerSensitiveDataController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:customers.view,admin')->group(function (): void {
    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->name('customers.show');
    Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
    Route::get('companies/{company}', [CompanyController::class, 'show'])->whereNumber('company')->name('companies.show');
});

Route::patch('customers/{customer}', [CustomerController::class, 'update'])->whereNumber('customer')
    ->middleware('permission:customers.update|pricing.manage|customers.manage,admin')->name('customers.update');
Route::patch('companies/{company}', [CompanyController::class, 'update'])->whereNumber('company')
    ->middleware('permission:customers.update|pricing.manage|customers.manage,admin')->name('companies.update');

Route::middleware('permission:customers.update,admin')->group(function (): void {
    Route::post('customers/{customer}/block', [CustomerBlockController::class, 'block'])->whereNumber('customer')->name('customers.block');
    Route::post('customers/{customer}/unblock', [CustomerBlockController::class, 'unblock'])->whereNumber('customer')->name('customers.unblock');
    Route::post('customers/{customer}/password-reset', [CustomerPasswordResetController::class, 'store'])->whereNumber('customer')->name('customers.password-reset');
});

Route::post('customers/{customer}/reveal-document', [CustomerSensitiveDataController::class, 'store'])->whereNumber('customer')
    ->middleware('permission:customers.view_sensitive,admin')->name('customers.reveal-document');
Route::post('customers/{customer}/anonymize', [CustomerAnonymizationController::class, 'store'])->whereNumber('customer')
    ->middleware('permission:customers.manage,admin')->name('customers.anonymize');
