<?php

use App\Http\Controllers\Activity\ActivityLogController;
use App\Http\Controllers\Admin\DatabaseHealthController;
use App\Http\Controllers\Admin\QueueStatusController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Dashboard\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('admin/queue-status', [QueueStatusController::class, 'show']);
    Route::get('reports/exports/{exportId}/status', [QueueStatusController::class, 'reportExportStatus']);
    Route::get('reports/exports/{exportId}/download', [QueueStatusController::class, 'reportExportDownload']);

    Route::get('notifications', [NotificationController::class, 'index'])
        ->middleware('spa.browser:notifications');
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])
        ->middleware('spa.browser:notifications');
    Route::get('notifications/poll', [NotificationController::class, 'poll'])
        ->middleware('spa.browser:notifications');
    Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllRead'])
        ->middleware('spa.browser:notifications');
    Route::post('notifications/{id}/read', [NotificationController::class, 'markRead'])
        ->middleware('spa.browser:notifications');

    Route::get('activity-logs', [ActivityLogController::class, 'index'])
        ->middleware('spa.browser:activity');

    Route::get('admin/db-health', [DatabaseHealthController::class, 'show']);
    Route::get('admin/security-matrix', [SecurityController::class, 'show']);
    Route::put('admin/security-matrix', [SecurityController::class, 'update']);
    Route::get('admin/database-health', fn () => view('crm.index', ['spaPage' => 'db-health']))
        ->middleware('spa.access:db-health');
});
