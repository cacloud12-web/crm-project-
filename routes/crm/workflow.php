<?php

use App\Http\Controllers\Workflow\LeadWorkflowController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('workflow/options', [LeadWorkflowController::class, 'options']);
    Route::get('workflow/lists', [LeadWorkflowController::class, 'lists']);
    Route::get('workflow/demo-history', [LeadWorkflowController::class, 'demoHistory']);
    Route::get('workflow/demos/resolve', [LeadWorkflowController::class, 'resolveDemo']);
    Route::post('workflow/calls', [LeadWorkflowController::class, 'recordCall'])
        ->middleware('throttle:follow-up');
    Route::post('workflow/demos', [LeadWorkflowController::class, 'scheduleDemo'])
        ->middleware('throttle:follow-up');
    Route::post('workflow/demos/{demoSchedule}/result', [LeadWorkflowController::class, 'recordDemoResult'])
        ->middleware('throttle:follow-up');
});
