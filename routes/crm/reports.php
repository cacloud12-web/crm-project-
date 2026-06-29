<?php

use App\Http\Controllers\Reports\ReportsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('reports', [ReportsController::class, 'index'])
        ->middleware('spa.browser:reports');
    Route::get('reports/analytics', [ReportsController::class, 'analytics'])
        ->middleware('spa.browser:reports');
    Route::get('reports/export/summary', [ReportsController::class, 'exportSummary'])
        ->middleware('spa.browser:reports');
    Route::get('reports/{slug}', [ReportsController::class, 'show'])
        ->middleware('spa.browser:reports');
    Route::get('reports/{slug}/export', [ReportsController::class, 'export'])
        ->middleware('spa.browser:reports');
});
