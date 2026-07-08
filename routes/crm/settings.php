<?php

use App\Http\Controllers\Settings\EmailAccountController;
use App\Http\Controllers\Settings\EmailSettingsController;
use App\Http\Controllers\Settings\GoogleApiSettingsController;
use App\Http\Controllers\Settings\SettingsController;
use App\Http\Controllers\Settings\SmsSettingsController;
use App\Http\Controllers\Settings\WhatsAppSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('email-settings', [EmailSettingsController::class, 'show'])
        ->middleware('spa.browser:settings');
    Route::put('email-settings', [EmailSettingsController::class, 'update'])
        ->middleware('spa.browser:settings');
    Route::post('email-settings/validate', [EmailSettingsController::class, 'validateConfiguration'])
        ->middleware('spa.browser:settings');
    Route::post('email-settings/send-test-email', [EmailSettingsController::class, 'sendTestEmail'])
        ->middleware('spa.browser:settings');

    Route::get('email-accounts', [EmailAccountController::class, 'index'])
        ->middleware('spa.browser:email-configuration');
    Route::post('email-accounts', [EmailAccountController::class, 'store'])
        ->middleware('spa.browser:email-configuration');
    Route::put('email-accounts/{id}', [EmailAccountController::class, 'update'])
        ->middleware('spa.browser:email-configuration');
    Route::delete('email-accounts/{id}', [EmailAccountController::class, 'destroy'])
        ->middleware('spa.browser:email-configuration');
    Route::post('email-accounts/{id}/set-default', [EmailAccountController::class, 'setDefault'])
        ->middleware('spa.browser:email-configuration');
    Route::post('email-accounts/test-smtp', [EmailAccountController::class, 'testSmtp'])
        ->middleware('spa.browser:email-configuration');
    Route::post('email-accounts/test-imap', [EmailAccountController::class, 'testImap'])
        ->middleware('spa.browser:email-configuration');
    Route::post('email-accounts/{id}/sync-imap', [EmailAccountController::class, 'syncImap'])
        ->middleware('spa.browser:email-configuration');

    Route::get('sms-settings', [SmsSettingsController::class, 'show'])
        ->middleware('spa.browser:settings');
    Route::put('sms-settings', [SmsSettingsController::class, 'update'])
        ->middleware('spa.browser:settings');
    Route::post('sms-settings/test', [SmsSettingsController::class, 'testConfiguration'])
        ->middleware('spa.browser:settings');
    Route::post('sms-settings/validate', [SmsSettingsController::class, 'testConfiguration'])
        ->middleware('spa.browser:settings');
    Route::post('sms-settings/test-connection', [SmsSettingsController::class, 'testConnection'])
        ->middleware('spa.browser:settings');
    Route::post('sms-settings/reset', [SmsSettingsController::class, 'reset'])
        ->middleware('spa.browser:settings');

    Route::get('whatsapp-settings', [WhatsAppSettingsController::class, 'show'])
        ->middleware('spa.browser:settings');
    Route::put('whatsapp-settings', [WhatsAppSettingsController::class, 'update'])
        ->middleware('spa.browser:settings');
    Route::post('whatsapp-settings/validate', [WhatsAppSettingsController::class, 'validateConfiguration'])
        ->middleware('spa.browser:settings');
    Route::post('whatsapp-settings/test-connection', [WhatsAppSettingsController::class, 'testConnection'])
        ->middleware('spa.browser:settings');
    Route::post('whatsapp-settings/send-test-template', [WhatsAppSettingsController::class, 'sendTestTemplate'])
        ->middleware('spa.browser:settings');
    Route::post('whatsapp-settings/reset', [WhatsAppSettingsController::class, 'reset'])
        ->middleware('spa.browser:settings');

    Route::get('google-api-settings', [GoogleApiSettingsController::class, 'show'])
        ->middleware('spa.browser:settings');
    Route::put('google-api-settings', [GoogleApiSettingsController::class, 'update'])
        ->middleware('spa.browser:settings');
    Route::post('google-api-settings/test', [GoogleApiSettingsController::class, 'test'])
        ->middleware('spa.browser:settings');

    Route::get('settings/data', [SettingsController::class, 'show']);
    Route::put('settings/data', [SettingsController::class, 'update']);
});
