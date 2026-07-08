<?php

use App\Http\Controllers\Templates\TemplateManagementController;
use App\Http\Controllers\Communication\ConsentTrackingController;
use App\Http\Controllers\Communication\DndManagementController;
use App\Http\Controllers\Communication\EmailCampaignController;
use App\Http\Controllers\Communication\EmailInboxController;
use App\Http\Controllers\Communication\EmailTemplateController;
use App\Http\Controllers\Communication\MessageTemplateController;
use App\Http\Controllers\Communication\SmsCampaignController;
use App\Http\Controllers\Communication\SmsTemplateController;
use App\Http\Controllers\Communication\UnifiedCampaignController;
use App\Http\Controllers\Communication\WhatsAppCampaignController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('campaigns', [UnifiedCampaignController::class, 'index'])
        ->middleware('spa.browser:communication');
    Route::get('campaigns/{channel}/{id}', [UnifiedCampaignController::class, 'show'])
        ->middleware('spa.browser:communication');
    Route::post('campaigns/{channel}/{id}/duplicate', [UnifiedCampaignController::class, 'duplicate'])
        ->middleware(['spa.browser:communication', 'throttle:campaign']);
    Route::post('campaigns/{channel}/{id}/pause', [UnifiedCampaignController::class, 'pause'])
        ->middleware(['spa.browser:communication', 'throttle:campaign']);
    Route::post('campaigns/{channel}/{id}/resume', [UnifiedCampaignController::class, 'resume'])
        ->middleware(['spa.browser:communication', 'throttle:campaign']);
    Route::post('campaigns/{channel}/{id}/cancel', [UnifiedCampaignController::class, 'cancel'])
        ->middleware(['spa.browser:communication', 'throttle:campaign']);
    Route::post('campaigns/{channel}/{id}/retry-failed', [UnifiedCampaignController::class, 'retryFailed'])
        ->middleware(['spa.browser:communication', 'throttle:campaign']);
    Route::get('campaigns/{channel}/{id}/export', [UnifiedCampaignController::class, 'export'])
        ->middleware('spa.browser:communication');
    Route::delete('campaigns/{channel}/{id}', [UnifiedCampaignController::class, 'destroy'])
        ->middleware('spa.browser:communication');

    Route::get('template-variables', [TemplateManagementController::class, 'variables'])
        ->middleware('spa.browser:settings');

    Route::get('email-templates', [EmailTemplateController::class, 'index'])
        ->middleware('spa.browser:email');
    Route::post('email-templates/preview', [EmailTemplateController::class, 'preview'])
        ->middleware(['spa.browser:email', 'throttle:campaign']);
    Route::post('email-templates', [EmailTemplateController::class, 'store'])
        ->middleware(['spa.browser:email', 'throttle:campaign']);
    Route::get('email-templates/{id}', [EmailTemplateController::class, 'show'])
        ->middleware('spa.browser:email');
    Route::put('email-templates/{id}', [EmailTemplateController::class, 'update'])
        ->middleware('spa.browser:email');
    Route::delete('email-templates/{id}', [EmailTemplateController::class, 'destroy'])
        ->middleware('spa.browser:email');
    Route::post('email-templates/{id}/duplicate', [EmailTemplateController::class, 'duplicate'])
        ->middleware('spa.browser:email');
    Route::post('email-templates/{id}/status', [EmailTemplateController::class, 'setStatus'])
        ->middleware('spa.browser:email');

    Route::get('message-templates/whatsapp', [MessageTemplateController::class, 'index'])
        ->middleware('spa.browser:whatsapp');
    Route::post('message-templates/whatsapp', [MessageTemplateController::class, 'store'])
        ->middleware(['spa.browser:whatsapp', 'throttle:campaign']);
    Route::get('message-templates/whatsapp/{id}', [MessageTemplateController::class, 'show'])
        ->middleware('spa.browser:whatsapp');
    Route::put('message-templates/whatsapp/{id}', [MessageTemplateController::class, 'update'])
        ->middleware('spa.browser:whatsapp');
    Route::delete('message-templates/whatsapp/{id}', [MessageTemplateController::class, 'destroy'])
        ->middleware('spa.browser:whatsapp');
    Route::post('message-templates/whatsapp/{id}/duplicate', [MessageTemplateController::class, 'duplicate'])
        ->middleware('spa.browser:whatsapp');
    Route::post('message-templates/whatsapp/{id}/status', [MessageTemplateController::class, 'setStatus'])
        ->middleware('spa.browser:whatsapp');
    Route::post('message-templates/whatsapp/{id}/preview', [MessageTemplateController::class, 'preview'])
        ->middleware(['spa.browser:whatsapp', 'throttle:campaign']);
    Route::post('message-templates/whatsapp/{id}/submit-meta', [MessageTemplateController::class, 'submitToMeta'])
        ->middleware('spa.browser:whatsapp');
    Route::get('message-templates/whatsapp/{id}/meta-payload', [MessageTemplateController::class, 'previewMetaPayload'])
        ->middleware('spa.browser:whatsapp');

    Route::post('whatsapp-campaigns/validate', [WhatsAppCampaignController::class, 'validatePreparation'])
        ->middleware(['spa.browser:whatsapp', 'throttle:campaign']);
    Route::post('whatsapp-campaigns/preview-message', [WhatsAppCampaignController::class, 'previewMessage'])
        ->middleware(['spa.browser:whatsapp', 'throttle:campaign']);
    Route::get('whatsapp-campaigns/{id}/payload-preview', [WhatsAppCampaignController::class, 'payloadPreview'])
        ->middleware(['spa.browser:whatsapp', 'throttle:campaign']);
    Route::get('whatsapp-campaigns', [WhatsAppCampaignController::class, 'index'])
        ->middleware('spa.browser:whatsapp');
    Route::post('whatsapp-campaigns', [WhatsAppCampaignController::class, 'store'])
        ->middleware(['spa.browser:whatsapp', 'throttle:campaign']);
    Route::get('whatsapp-campaigns/{id}', [WhatsAppCampaignController::class, 'show'])
        ->middleware('spa.browser:whatsapp');
    Route::post('whatsapp-campaigns/{id}/process', [WhatsAppCampaignController::class, 'process'])
        ->middleware(['spa.browser:whatsapp', 'throttle:campaign']);
    Route::put('whatsapp-campaigns/{id}', [WhatsAppCampaignController::class, 'update'])
        ->middleware('spa.browser:whatsapp');
    Route::delete('whatsapp-campaigns/{id}', [WhatsAppCampaignController::class, 'destroy'])
        ->middleware('spa.browser:whatsapp');
    Route::get('wa-message-logs', [WhatsAppCampaignController::class, 'messageLogs'])
        ->middleware('spa.browser:whatsapp');

    Route::get('email-campaigns/{id}/payload-preview', [EmailCampaignController::class, 'payloadPreview'])
        ->middleware(['spa.browser:email', 'throttle:campaign']);
    Route::get('email-campaigns', [EmailCampaignController::class, 'index'])
        ->middleware('spa.browser:email');
    Route::post('email-campaigns', [EmailCampaignController::class, 'store'])
        ->middleware(['spa.browser:email', 'throttle:campaign']);
    Route::get('email-campaigns/{id}', [EmailCampaignController::class, 'show'])
        ->middleware('spa.browser:email');
    Route::post('email-campaigns/{id}/process', [EmailCampaignController::class, 'process'])
        ->middleware(['spa.browser:email', 'throttle:campaign']);
    Route::post('email-campaigns/{id}/retry-failed', [EmailCampaignController::class, 'retryFailed'])
        ->middleware(['spa.browser:email', 'throttle:campaign']);
    Route::put('email-campaigns/{id}', [EmailCampaignController::class, 'update'])
        ->middleware('spa.browser:email');
    Route::delete('email-campaigns/{id}', [EmailCampaignController::class, 'destroy'])
        ->middleware('spa.browser:email');
    Route::get('email-logs', [EmailCampaignController::class, 'emailLogs'])
        ->middleware('spa.browser:email');

    Route::post('email-inbox/sync', [EmailInboxController::class, 'sync'])
        ->middleware('spa.browser:email');
    Route::get('email-inbox/metrics', [EmailInboxController::class, 'metrics'])
        ->middleware('spa.browser:email');
    Route::get('email-inbox/attachments/{id}', [EmailInboxController::class, 'downloadAttachment'])
        ->middleware('spa.browser:email');
    Route::get('email-inbox', [EmailInboxController::class, 'index'])
        ->middleware('spa.browser:email');
    Route::get('email-inbox/{id}', [EmailInboxController::class, 'show'])
        ->middleware('spa.browser:email');
    Route::post('email-inbox/{id}/mark-read', [EmailInboxController::class, 'markRead'])
        ->middleware('spa.browser:email');

    Route::get('sms-templates', [SmsTemplateController::class, 'index'])
        ->middleware('spa.browser:sms');
    Route::post('sms-templates', [SmsTemplateController::class, 'store'])
        ->middleware(['spa.browser:sms', 'throttle:campaign']);
    Route::put('sms-templates/{id}', [SmsTemplateController::class, 'update'])
        ->middleware('spa.browser:sms');
    Route::delete('sms-templates/{id}', [SmsTemplateController::class, 'destroy'])
        ->middleware('spa.browser:sms');
    Route::post('sms-templates/preview', [SmsTemplateController::class, 'preview'])
        ->middleware(['spa.browser:sms', 'throttle:campaign']);

    Route::post('sms-campaigns/validate', [SmsCampaignController::class, 'validatePreparation'])
        ->middleware(['spa.browser:sms', 'throttle:campaign']);
    Route::post('sms-campaigns/preview-message', [SmsCampaignController::class, 'previewMessage'])
        ->middleware(['spa.browser:sms', 'throttle:campaign']);
    Route::get('sms-campaigns/{id}/payload-preview', [SmsCampaignController::class, 'payloadPreview'])
        ->middleware(['spa.browser:sms', 'throttle:campaign']);
    Route::post('sms-campaigns/{id}/generate-payload-preview', [SmsCampaignController::class, 'generatePayloadPreview'])
        ->middleware(['spa.browser:sms', 'throttle:campaign']);
    Route::get('sms-campaigns', [SmsCampaignController::class, 'index'])
        ->middleware('spa.browser:sms');
    Route::post('sms-campaigns', [SmsCampaignController::class, 'store'])
        ->middleware(['spa.browser:sms', 'throttle:campaign']);
    Route::get('sms-campaigns/{id}', [SmsCampaignController::class, 'show'])
        ->middleware('spa.browser:sms');
    Route::post('sms-campaigns/{id}/process', [SmsCampaignController::class, 'process'])
        ->middleware(['spa.browser:sms', 'throttle:campaign']);
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
