<?php

use App\Http\Controllers\Assignment\AssignmentHistoryController;
use App\Http\Controllers\Assignment\BulkAssignmentController;
use App\Http\Controllers\Assignment\DailyEmployeeTargetController;
use App\Http\Controllers\Assignment\YearlyEmployeeTargetController;
use App\Http\Controllers\Assignment\LeadAssignmentEngineController;
use App\Http\Controllers\Employees\EmployeeController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AssignmentDashboardController;


Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('assignment-dashboard/capacity', [AssignmentDashboardController::class, 'capacity'])
        ->middleware('spa.browser:assignment');
    Route::put('assignment-dashboard/capacity', [AssignmentDashboardController::class, 'updateCapacity'])
        ->middleware('spa.browser:assignment');
    Route::get('assignment-dashboard/heat-map', [AssignmentDashboardController::class, 'heatMap'])
        ->middleware('spa.browser:assignment');

    Route::get('yearly-employee-targets/summary', [YearlyEmployeeTargetController::class, 'summary'])
        ->middleware('spa.browser:assignment');
    Route::get('yearly-employee-targets/calendar-summary', [YearlyEmployeeTargetController::class, 'calendarSummary'])
        ->middleware('spa.browser:assignment');
    Route::post('yearly-employee-targets/recalculate', [YearlyEmployeeTargetController::class, 'recalculate'])
        ->middleware('spa.browser:assignment');
    Route::get('yearly-employee-targets/current-year', [YearlyEmployeeTargetController::class, 'currentYear']);
    Route::get('yearly-employee-targets/holidays', [YearlyEmployeeTargetController::class, 'holidays'])
        ->middleware('spa.browser:assignment');
    Route::put('yearly-employee-targets/holiday-dates', [YearlyEmployeeTargetController::class, 'syncHolidayDates'])
        ->middleware('spa.browser:assignment');
    Route::put('yearly-employee-targets/holidays', [YearlyEmployeeTargetController::class, 'syncHolidays'])
        ->middleware('spa.browser:assignment');
    Route::get('yearly-employee-targets/{employeeId}/leaves', [YearlyEmployeeTargetController::class, 'leaves'])
        ->middleware('spa.browser:assignment');
    Route::post('yearly-employee-targets/leaves', [YearlyEmployeeTargetController::class, 'storeLeave'])
        ->middleware('spa.browser:assignment');
    Route::post('yearly-employee-targets/leaves/{employeeLeave}/approve', [YearlyEmployeeTargetController::class, 'approveLeave'])
        ->middleware('spa.browser:assignment');
    Route::post('yearly-employee-targets/leaves/{employeeLeave}/reject', [YearlyEmployeeTargetController::class, 'rejectLeave'])
        ->middleware('spa.browser:assignment');
    Route::get('yearly-employee-targets/{employeeId}/calendar', [YearlyEmployeeTargetController::class, 'calendar'])
        ->middleware('spa.browser:assignment');
    Route::resource('yearly-employee-targets', YearlyEmployeeTargetController::class)
        ->middleware('spa.browser:assignment')
        ->parameters(['yearly-employee-targets' => 'yearlyEmployeeTarget']);

    Route::get('daily-employee-targets/summary', [DailyEmployeeTargetController::class, 'summary'])
        ->middleware('spa.browser:assignment');
    Route::get('daily-employee-targets/history', [DailyEmployeeTargetController::class, 'history'])
        ->middleware('spa.browser:assignment');
    Route::get('daily-employee-targets/today', [DailyEmployeeTargetController::class, 'today']);
    Route::post('daily-employee-targets/copy-yesterday', [DailyEmployeeTargetController::class, 'copyYesterday'])
        ->middleware('spa.browser:assignment');
    Route::post('daily-employee-targets/copy-to-employees', [DailyEmployeeTargetController::class, 'copyToEmployees'])
        ->middleware('spa.browser:assignment');
    Route::post('daily-employee-targets/copy-to-team', [DailyEmployeeTargetController::class, 'copyToTeam'])
        ->middleware('spa.browser:assignment');
    Route::post('daily-employee-targets/copy-weekdays', [DailyEmployeeTargetController::class, 'copyWeekdays'])
        ->middleware('spa.browser:assignment');
    Route::resource('daily-employee-targets', DailyEmployeeTargetController::class)
        ->middleware('spa.browser:assignment');
    Route::get('lead-assignments/bulk/batches', [BulkAssignmentController::class, 'batches']);
    Route::get('lead-assignments/bulk/leads', [BulkAssignmentController::class, 'leads']);
    Route::get('lead-assignments/bulk/leads/ids', [BulkAssignmentController::class, 'leadIds']);
    Route::get('lead-assignments/bulk/employees', [BulkAssignmentController::class, 'employees']);
    Route::post('lead-assignments/bulk', [BulkAssignmentController::class, 'store']);

    Route::get('assignment-histories', [AssignmentHistoryController::class, 'index']);
    Route::patch('lead-assignments/{lead_assignment}/status', [LeadAssignmentEngineController::class, 'updateStatus'])
        ->middleware('spa.browser:assignment');
    Route::resource('lead-assignments', LeadAssignmentEngineController::class)
        ->middleware('spa.browser:assignment');

    Route::post('employees/provision-logins', [EmployeeController::class, 'provisionLogins'])
        ->middleware('spa.browser:employees');
    Route::post('employees/{employee}/reset-password', [EmployeeController::class, 'resetPassword'])
        ->middleware('spa.browser:employees');
    Route::resource('employees', EmployeeController::class)
        ->middleware('spa.browser:employees');
});
