<?php

use App\Http\Controllers\Sales\SalesListController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('sales-list/export', [SalesListController::class, 'export'])
        ->middleware('spa.browser:sales-list');
    Route::get('sales-list/options', [SalesListController::class, 'options'])
        ->middleware('spa.browser:sales-list');
    Route::get('sales-list', [SalesListController::class, 'index'])
        ->middleware('spa.browser:sales-list');
    Route::get('sales-list/{id}/history', [SalesListController::class, 'history'])
        ->middleware('spa.browser:sales-list');
    Route::get('sales-list/{id}', [SalesListController::class, 'show'])
        ->middleware('spa.browser:sales-list');
    Route::patch('sales-list/{id}', [SalesListController::class, 'update'])
        ->middleware('spa.browser:sales-list');
});
