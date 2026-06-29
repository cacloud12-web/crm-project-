<?php

use App\Http\Controllers\Assignment\AssignmentHistoryController;
use App\Http\Controllers\Assignment\BulkAssignmentController;
use App\Http\Controllers\Assignment\LeadAssignmentEngineController;
use App\Http\Controllers\Employees\EmployeeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('lead-assignments/bulk/batches', [BulkAssignmentController::class, 'batches']);
    Route::get('lead-assignments/bulk/leads', [BulkAssignmentController::class, 'leads']);
    Route::get('lead-assignments/bulk/leads/ids', [BulkAssignmentController::class, 'leadIds']);
    Route::get('lead-assignments/bulk/employees', [BulkAssignmentController::class, 'employees']);
    Route::post('lead-assignments/bulk', [BulkAssignmentController::class, 'store']);

    Route::get('assignment-histories', [AssignmentHistoryController::class, 'index']);
    Route::resource('lead-assignments', LeadAssignmentEngineController::class)
        ->middleware('spa.browser:assignment');

    Route::post('employees/provision-logins', [EmployeeController::class, 'provisionLogins'])
        ->middleware('spa.browser:employees');
    Route::post('employees/{employee}/reset-password', [EmployeeController::class, 'resetPassword'])
        ->middleware('spa.browser:employees');
    Route::resource('employees', EmployeeController::class)
        ->middleware('spa.browser:employees');
});
