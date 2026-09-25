<?php

/*
|--------------------------------------------------------------------------
| Reports — admin panel
|--------------------------------------------------------------------------
| Prefix: /api/v1/admin · route names: "admin.*"
| middleware: api, auth:admin, admin.fresh, throttle:admin. Every route must declare a permission (permission:<name>,admin or ->can()).
| Loaded automatically by App\Shared\Providers\ModuleServiceProvider.
*/

use App\Modules\Reports\Http\Controllers\Admin\DashboardController;
use App\Modules\Reports\Http\Controllers\Admin\ReportController;
use App\Modules\Reports\Support\ReportRegistry;
use Illuminate\Support\Facades\Route;

Route::get('dashboard', [DashboardController::class, 'show'])
    ->middleware(['permission:dashboard.view,admin', 'throttle:admin-dashboard'])
    ->name('dashboard.show');

// Per-report permission (reports.view | reports.sales | reports.inventory, + reports.export for CSV)
// is enforced by ReportRequest::authorize(); the middleware requires at least one report permission.
Route::get('reports/{report}', [ReportController::class, 'show'])
    ->whereIn('report', array_keys(ReportRegistry::REPORTS))
    ->middleware(['permission:reports.view|reports.sales|reports.inventory,admin', 'throttle:admin-heavy'])
    ->name('reports.show');
