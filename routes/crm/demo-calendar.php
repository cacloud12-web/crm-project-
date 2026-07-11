<?php

use App\Http\Controllers\Demo\DemoCalendarController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('demo-calendar/summary', [DemoCalendarController::class, 'summary']);
    Route::get('demo-calendar/events', [DemoCalendarController::class, 'events']);
    Route::get('demo-calendar/available-slots', [DemoCalendarController::class, 'availableSlots']);
    Route::get('demo-calendar/providers', [DemoCalendarController::class, 'providers']);
    Route::post('demo-calendar/check-conflict', [DemoCalendarController::class, 'checkConflict']);
    Route::post('demo-calendar/schedule', [DemoCalendarController::class, 'schedule'])
        ->middleware('throttle:follow-up');

    Route::get('demo-calendar/providers/settings', [DemoCalendarController::class, 'providerSettings']);
    Route::post('demo-calendar/providers', [DemoCalendarController::class, 'createProvider']);
    Route::put('demo-calendar/providers/{providerId}', [DemoCalendarController::class, 'updateProvider']);

    Route::patch('demo-calendar/schedules/{demoSchedule}/reschedule', [DemoCalendarController::class, 'reschedule'])
        ->middleware('throttle:follow-up');
    Route::patch('demo-calendar/schedules/{demoSchedule}/cancel', [DemoCalendarController::class, 'cancel'])
        ->middleware('throttle:follow-up');
    Route::patch('demo-calendar/schedules/{demoSchedule}/complete', [DemoCalendarController::class, 'complete']);
    Route::patch('demo-calendar/schedules/{demoSchedule}/missed', [DemoCalendarController::class, 'missed']);
});
