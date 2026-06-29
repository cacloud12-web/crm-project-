<?php

use App\Http\Controllers\Settings\EmailSettingsController;
use App\Http\Controllers\Settings\SettingsController;
use App\Http\Controllers\Settings\SmsSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('email-settings', [EmailSettingsController::class, 'show'])
        ->middleware('spa.browser:settings');
    Route::put('email-settings', [EmailSettingsController::class, 'update'])
        ->middleware('spa.browser:settings');

    Route::get('sms-settings', [SmsSettingsController::class, 'show'])
        ->middleware('spa.browser:settings');
    Route::put('sms-settings', [SmsSettingsController::class, 'update'])
        ->middleware('spa.browser:settings');
    Route::post('sms-settings/test', [SmsSettingsController::class, 'testConfiguration'])
        ->middleware('spa.browser:settings');
    Route::post('sms-settings/validate', [SmsSettingsController::class, 'testConfiguration'])
        ->middleware('spa.browser:settings');
    Route::post('sms-settings/reset', [SmsSettingsController::class, 'reset'])
        ->middleware('spa.browser:settings');

    Route::get('settings/data', [SettingsController::class, 'show']);
    Route::put('settings/data', [SettingsController::class, 'update']);
});
