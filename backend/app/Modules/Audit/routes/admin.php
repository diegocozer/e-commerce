<?php

/*
|--------------------------------------------------------------------------
| Audit — admin panel (API.md §3.G.14), read-only
|--------------------------------------------------------------------------
*/

use App\Modules\Audit\Http\Controllers\Admin\AuditLogController;
use App\Modules\Audit\Http\Controllers\Admin\FailedJobController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:audit_logs.view,admin')->group(function (): void {
    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show'])->whereNumber('auditLog')->name('audit-logs.show');
    Route::get('failed-jobs', [FailedJobController::class, 'index'])->name('failed-jobs.index');
});
