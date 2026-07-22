<?php

use App\Http\Controllers\Mapping\SalesImportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('employee-imports/summary', [SalesImportController::class, 'summary'])
        ->middleware('spa.browser:ca-master')
        ->name('employee-imports.summary');

    Route::get('employee-imports/files', [SalesImportController::class, 'files'])
        ->middleware('spa.browser:ca-master')
        ->name('employee-imports.files');

    Route::get('employee-imports/data', [SalesImportController::class, 'index'])
        ->middleware('spa.browser:ca-master')
        ->name('employee-imports.index');

    Route::get('employee-imports/reference-search', [SalesImportController::class, 'searchReference'])
        ->middleware('spa.browser:ca-master')
        ->name('employee-imports.reference-search');

    Route::post('employee-imports/accept-all-matched', [SalesImportController::class, 'acceptAllMatched'])
        ->middleware(['spa.browser:ca-master', 'throttle:lead-action'])
        ->name('employee-imports.accept-all-matched');

    Route::get('employee-imports/{salesImportRow}', [SalesImportController::class, 'show'])
        ->whereNumber('salesImportRow')
        ->middleware('spa.browser:ca-master')
        ->name('employee-imports.show');

    Route::get('employee-imports/{salesImportRow}/candidates', [SalesImportController::class, 'candidates'])
        ->whereNumber('salesImportRow')
        ->middleware('spa.browser:ca-master')
        ->name('employee-imports.candidates');

    Route::post('employee-imports/{salesImportRow}/confirm-match', [SalesImportController::class, 'confirmMatch'])
        ->whereNumber('salesImportRow')
        ->middleware(['spa.browser:ca-master', 'throttle:lead-action'])
        ->name('employee-imports.confirm-match');

    Route::post('employee-imports/{salesImportRow}/accept-top', [SalesImportController::class, 'acceptBestCandidate'])
        ->whereNumber('salesImportRow')
        ->middleware(['spa.browser:ca-master', 'throttle:lead-action'])
        ->name('employee-imports.accept-top');

    Route::post('employee-imports/{salesImportRow}/mark-unmatched', [SalesImportController::class, 'markUnmatched'])
        ->whereNumber('salesImportRow')
        ->middleware(['spa.browser:ca-master', 'throttle:lead-action'])
        ->name('employee-imports.mark-unmatched');

    Route::post('employee-imports/{salesImportRow}/ignore', [SalesImportController::class, 'ignore'])
        ->whereNumber('salesImportRow')
        ->middleware(['spa.browser:ca-master', 'throttle:lead-action'])
        ->name('employee-imports.ignore');
});
