<?php

use App\Http\Controllers\FollowUp\FollowUpAutomationController;
use App\Http\Controllers\FollowUp\FollowUpController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::post('follow-ups/call-outcome', [FollowUpAutomationController::class, 'recordCallOutcome'])
        ->middleware('throttle:follow-up');
    Route::get('follow-ups/activity-timeline', [FollowUpAutomationController::class, 'activityTimeline']);
    Route::get('follow-ups/manager-metrics', [FollowUpAutomationController::class, 'managerMetrics']);
    Route::get('follow-ups/sequence', [FollowUpAutomationController::class, 'sequenceShow']);
    Route::put('follow-ups/sequence', [FollowUpAutomationController::class, 'sequenceUpdate']);
    Route::get('follow-ups/tasks', [FollowUpAutomationController::class, 'tasks']);
    Route::get('follow-ups/{followup}/history', [FollowUpAutomationController::class, 'followUpHistory']);
    Route::get('ca-masters/{caId}/follow-up-history', [FollowUpAutomationController::class, 'leadHistory']);

    Route::get('follow-ups', [FollowUpController::class, 'index'])
        ->middleware('spa.browser:followups');
    Route::get('follow-ups/{follow_up}', [FollowUpController::class, 'show'])
        ->middleware('spa.browser:followups');
    Route::post('follow-ups', [FollowUpController::class, 'store'])
        ->middleware(['spa.browser:followups', 'throttle:follow-up']);
    Route::put('follow-ups/{follow_up}', [FollowUpController::class, 'update'])
        ->middleware(['spa.browser:followups', 'throttle:follow-up']);
    Route::patch('follow-ups/{follow_up}', [FollowUpController::class, 'update'])
        ->middleware(['spa.browser:followups', 'throttle:follow-up']);
    Route::delete('follow-ups/{follow_up}', [FollowUpController::class, 'destroy'])
        ->middleware('spa.browser:followups');
});
