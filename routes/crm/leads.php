<?php

use App\Http\Controllers\Approval\ApprovalRequestController;
use App\Http\Controllers\Leads\CaMasterController;
use App\Http\Controllers\Leads\DemoConfirmationController;
use App\Http\Controllers\Leads\LeadActionController;
use App\Http\Controllers\Leads\DuplicateAttemptController;
use App\Http\Controllers\Leads\LeadDuplicateCheckController;
use App\Http\Controllers\Leads\LeadEmailCommunicationController;
use App\Http\Controllers\Leads\LeadResearchController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('ca-masters/check-duplicate', LeadDuplicateCheckController::class)
        ->middleware('throttle:60,1');
    Route::get('duplicate-attempts/metrics', [DuplicateAttemptController::class, 'metrics'])
        ->middleware('spa.browser:ca-master');
    Route::get('duplicate-attempts/export', [DuplicateAttemptController::class, 'export'])
        ->middleware('spa.browser:ca-master');
    Route::get('duplicate-attempts', [DuplicateAttemptController::class, 'index'])
        ->middleware('spa.browser:ca-master');
    Route::post('duplicate-attempts/{id}/mark-changed', [DuplicateAttemptController::class, 'markChanged'])
        ->middleware('spa.browser:ca-master');
    Route::get('ca-masters/segment-counts', [CaMasterController::class, 'segmentCounts'])
        ->middleware('spa.browser:ca-master');
    Route::get('ca-masters/kanban', [CaMasterController::class, 'kanban'])
        ->middleware('spa.browser:ca-master');
    Route::get('ca-masters/{lead}/demo-confirmation', [DemoConfirmationController::class, 'showForLead'])
        ->middleware('spa.browser:leads');
    Route::get('ca-masters/{caId}/email-communications', [LeadEmailCommunicationController::class, 'index'])
        ->middleware('spa.browser:leads');
    Route::post('lead-actions', [LeadActionController::class, 'store'])
        ->middleware('throttle:lead-action');
    Route::patch('ca-masters/{ca_master}/status', [CaMasterController::class, 'updateStatus'])
        ->middleware(['spa.browser:ca-master', 'throttle:lead-action']);
    Route::patch('ca-masters/{ca_master}/contact', [CaMasterController::class, 'updateContact'])
        ->middleware(['spa.browser:leads', 'throttle:lead-action']);
    Route::post('ca-masters/{ca_master}/lock', [CaMasterController::class, 'acquireLock'])
        ->middleware(['spa.browser:leads', 'throttle:lead-action']);
    Route::delete('ca-masters/{ca_master}/lock', [CaMasterController::class, 'releaseLock'])
        ->middleware(['spa.browser:leads', 'throttle:lead-action']);
    Route::post('ca-masters/{ca_master}/research', [LeadResearchController::class, 'research'])
        ->middleware(['spa.browser:leads', 'throttle:30,1']);
    Route::post('ca-masters/{ca_master}/research/refresh', [LeadResearchController::class, 'refresh'])
        ->middleware(['spa.browser:leads', 'throttle:lead-action']);
    Route::post('ca-masters/{ca_master}/research/select', [LeadResearchController::class, 'select'])
        ->middleware(['spa.browser:leads', 'throttle:30,1']);
    Route::post('ca-masters/{ca_master}/research/save', [LeadResearchController::class, 'save'])
        ->middleware(['spa.browser:leads', 'throttle:lead-action']);
    Route::post('ca-masters/bulk-delete', [CaMasterController::class, 'bulkDestroy'])
        ->middleware(['spa.browser:ca-master', 'throttle:lead-action']);
    Route::get('ca-masters/trashed', [CaMasterController::class, 'trashed'])
        ->middleware('spa.browser:ca-master');
    Route::post('ca-masters/trashed/restore', [CaMasterController::class, 'bulkRestore'])
        ->middleware(['spa.browser:ca-master', 'throttle:lead-action']);
    Route::post('ca-masters/trashed/force-delete', [CaMasterController::class, 'bulkForceDestroy'])
        ->middleware(['spa.browser:ca-master', 'throttle:lead-action']);
    Route::post('ca-masters/{id}/restore', [CaMasterController::class, 'restore'])
        ->middleware(['spa.browser:ca-master', 'throttle:lead-action']);
    Route::delete('ca-masters/{id}/force', [CaMasterController::class, 'forceDestroy'])
        ->middleware(['spa.browser:ca-master', 'throttle:lead-action']);
    Route::resource('ca-masters', CaMasterController::class)
        ->middleware('spa.browser:ca-master');

    Route::get('approval-requests', [ApprovalRequestController::class, 'index']);
    Route::post('approval-requests', [ApprovalRequestController::class, 'store']);
    Route::post('approval-requests/{id}/approve', [ApprovalRequestController::class, 'approve']);
    Route::post('approval-requests/{id}/reject', [ApprovalRequestController::class, 'reject']);
});
