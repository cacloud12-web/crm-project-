<?php

use App\Http\Controllers\Communication\ConsentTrackingController;
use App\Http\Controllers\Communication\DndManagementController;
use App\Http\Controllers\Communication\EmailCampaignController;
use App\Http\Controllers\Communication\SmsCampaignController;
use App\Http\Controllers\Communication\WhatsAppCampaignController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('whatsapp-campaigns', [WhatsAppCampaignController::class, 'index'])
        ->middleware('spa.browser:whatsapp');
    Route::post('whatsapp-campaigns', [WhatsAppCampaignController::class, 'store'])
        ->middleware('spa.browser:whatsapp');
    Route::get('whatsapp-campaigns/{id}', [WhatsAppCampaignController::class, 'show'])
        ->middleware('spa.browser:whatsapp');
    Route::post('whatsapp-campaigns/{id}/process', [WhatsAppCampaignController::class, 'process'])
        ->middleware('spa.browser:whatsapp');
    Route::put('whatsapp-campaigns/{id}', [WhatsAppCampaignController::class, 'update'])
        ->middleware('spa.browser:whatsapp');
    Route::delete('whatsapp-campaigns/{id}', [WhatsAppCampaignController::class, 'destroy'])
        ->middleware('spa.browser:whatsapp');
    Route::get('wa-message-logs', [WhatsAppCampaignController::class, 'messageLogs'])
        ->middleware('spa.browser:whatsapp');

    Route::get('email-campaigns/{id}/payload-preview', [EmailCampaignController::class, 'payloadPreview'])
        ->middleware('spa.browser:email');
    Route::get('email-campaigns', [EmailCampaignController::class, 'index'])
        ->middleware('spa.browser:email');
    Route::post('email-campaigns', [EmailCampaignController::class, 'store'])
        ->middleware('spa.browser:email');
    Route::get('email-campaigns/{id}', [EmailCampaignController::class, 'show'])
        ->middleware('spa.browser:email');
    Route::post('email-campaigns/{id}/process', [EmailCampaignController::class, 'process'])
        ->middleware('spa.browser:email');
    Route::put('email-campaigns/{id}', [EmailCampaignController::class, 'update'])
        ->middleware('spa.browser:email');
    Route::delete('email-campaigns/{id}', [EmailCampaignController::class, 'destroy'])
        ->middleware('spa.browser:email');
    Route::get('email-logs', [EmailCampaignController::class, 'emailLogs'])
        ->middleware('spa.browser:email');

    Route::post('sms-campaigns/validate', [SmsCampaignController::class, 'validatePreparation'])
        ->middleware('spa.browser:sms');
    Route::post('sms-campaigns/preview-message', [SmsCampaignController::class, 'previewMessage'])
        ->middleware('spa.browser:sms');
    Route::get('sms-campaigns/{id}/payload-preview', [SmsCampaignController::class, 'payloadPreview'])
        ->middleware('spa.browser:sms');
    Route::post('sms-campaigns/{id}/generate-payload-preview', [SmsCampaignController::class, 'generatePayloadPreview'])
        ->middleware('spa.browser:sms');
    Route::get('sms-campaigns', [SmsCampaignController::class, 'index'])
        ->middleware('spa.browser:sms');
    Route::post('sms-campaigns', [SmsCampaignController::class, 'store'])
        ->middleware('spa.browser:sms');
    Route::get('sms-campaigns/{id}', [SmsCampaignController::class, 'show'])
        ->middleware('spa.browser:sms');
    Route::post('sms-campaigns/{id}/process', [SmsCampaignController::class, 'process'])
        ->middleware('spa.browser:sms');
    Route::put('sms-campaigns/{id}', [SmsCampaignController::class, 'update'])
        ->middleware('spa.browser:sms');
    Route::delete('sms-campaigns/{id}', [SmsCampaignController::class, 'destroy'])
        ->middleware('spa.browser:sms');
    Route::get('sms-logs', [SmsCampaignController::class, 'smsLogs'])
        ->middleware('spa.browser:sms');

    Route::get('consent-trackings', [ConsentTrackingController::class, 'index'])
        ->middleware('spa.browser:consent-dnd');
    Route::post('consent-trackings', [ConsentTrackingController::class, 'store'])
        ->middleware('spa.browser:consent-dnd');

    Route::get('dnd-management', [DndManagementController::class, 'index'])
        ->middleware('spa.browser:consent-dnd');
    Route::post('dnd-management', [DndManagementController::class, 'store'])
        ->middleware('spa.browser:consent-dnd');
    Route::delete('dnd-management/{id}', [DndManagementController::class, 'destroy'])
        ->middleware('spa.browser:consent-dnd');
});
