<?php

use App\Http\Controllers\Ticket\CaCloudDeskInboundTicketController;
use App\Http\Controllers\Webhooks\WhatsAppWebhookController;
use App\Http\Middleware\VerifyCaCloudDeskIntegrationToken;
use Illuminate\Support\Facades\Route;

Route::get('webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify']);
Route::post('webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive']);

Route::post('webhooks/ca-cloud-desk/tickets', [CaCloudDeskInboundTicketController::class, 'store'])
    ->middleware([VerifyCaCloudDeskIntegrationToken::class, 'throttle:60,1']);
