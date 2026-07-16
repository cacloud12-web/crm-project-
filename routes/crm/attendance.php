<?php

use App\Http\Controllers\Attendance\EmployeeAttendanceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('attendance/summary', [EmployeeAttendanceController::class, 'summary']);
    Route::get('attendance', [EmployeeAttendanceController::class, 'index']);
    Route::post('attendance', [EmployeeAttendanceController::class, 'store'])
        ->middleware('throttle:60,1');
    Route::post('attendance/bulk', [EmployeeAttendanceController::class, 'bulkStore'])
        ->middleware('throttle:30,1');
});
