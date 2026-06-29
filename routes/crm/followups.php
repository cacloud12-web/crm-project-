<?php

use App\Http\Controllers\FollowUp\FollowUpAutomationController;
use App\Http\Controllers\FollowUp\FollowUpController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::post('follow-ups/call-outcome', [FollowUpAutomationController::class, 'recordCallOutcome']);
    Route::get('follow-ups/manager-metrics', [FollowUpAutomationController::class, 'managerMetrics']);
    Route::get('follow-ups/sequence', [FollowUpAutomationController::class, 'sequenceShow']);
    Route::put('follow-ups/sequence', [FollowUpAutomationController::class, 'sequenceUpdate']);
    Route::get('follow-ups/tasks', [FollowUpAutomationController::class, 'tasks']);
    Route::get('follow-ups/{followup}/history', [FollowUpAutomationController::class, 'followUpHistory']);
    Route::get('ca-masters/{caId}/follow-up-history', [FollowUpAutomationController::class, 'leadHistory']);
    Route::resource('follow-ups', FollowUpController::class)
        ->middleware('spa.browser:followups');
});
