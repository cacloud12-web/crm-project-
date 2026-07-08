<?php

use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\GlobalSearchController;
use App\Http\Controllers\Leads\DemoConfirmationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('dashboard/metrics', [DashboardController::class, 'metrics']);
    Route::get('dashboard/productivity-employees', [DashboardController::class, 'productivityEmployees']);
    Route::get('dashboard/employee', [DashboardController::class, 'employeeMetrics']);
    Route::get('demo-confirmations/metrics', [DemoConfirmationController::class, 'metrics'])
        ->middleware('spa.browser:dashboard');
    Route::post('demo-confirmations/inbound-reply', [DemoConfirmationController::class, 'inboundReply'])
        ->middleware('spa.browser:dashboard');
    Route::get('search', [GlobalSearchController::class, 'index']);
});
