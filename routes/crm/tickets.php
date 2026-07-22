<?php

use App\Http\Controllers\Ticket\SupportTicketController;
use App\Http\Controllers\Ticket\TicketAttachmentController;
use App\Http\Controllers\Ticket\TicketCommentController;
use App\Http\Controllers\Ticket\TicketOrganizationLookupController;
use App\Models\SupportTicket;
use App\Models\TicketAttachment;
use Illuminate\Support\Facades\Route;

Route::bind('ticket', fn (string $value) => SupportTicket::query()->findOrFail($value));
Route::bind('attachment', fn (string $value) => TicketAttachment::query()->findOrFail($value));

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('tickets/metadata', [SupportTicketController::class, 'metadata'])
        ->middleware('spa.browser:tickets');

    Route::get('ticket-organizations', [TicketOrganizationLookupController::class, 'index'])
        ->middleware(['spa.browser:tickets', 'throttle:ticket-action']);
    Route::post('ticket-organizations/verify', [TicketOrganizationLookupController::class, 'verify'])
        ->middleware(['spa.browser:tickets', 'throttle:ticket-action']);

    Route::get('tickets', [SupportTicketController::class, 'index'])
        ->middleware('spa.browser:tickets');
    Route::post('tickets', [SupportTicketController::class, 'store'])
        ->middleware(['spa.browser:tickets', 'throttle:ticket-action']);
    Route::get('tickets/{ticket}', [SupportTicketController::class, 'show'])
        ->middleware('spa.browser:tickets');
    Route::put('tickets/{ticket}', [SupportTicketController::class, 'update'])
        ->middleware(['spa.browser:tickets', 'throttle:ticket-action']);
    Route::patch('tickets/{ticket}', [SupportTicketController::class, 'update'])
        ->middleware(['spa.browser:tickets', 'throttle:ticket-action']);
    Route::post('tickets/{ticket}/status', [SupportTicketController::class, 'changeStatus'])
        ->middleware(['spa.browser:tickets', 'throttle:ticket-action']);
    Route::post('tickets/{ticket}/assign', [SupportTicketController::class, 'assign'])
        ->middleware(['spa.browser:tickets', 'throttle:ticket-action']);
    Route::delete('tickets/{ticket}', [SupportTicketController::class, 'destroy'])
        ->middleware('spa.browser:tickets');
    Route::get('tickets/{ticket}/history', [SupportTicketController::class, 'history'])
        ->middleware('spa.browser:tickets');

    Route::get('tickets/{ticket}/comments', [TicketCommentController::class, 'index'])
        ->middleware('spa.browser:tickets');
    Route::post('tickets/{ticket}/comments', [TicketCommentController::class, 'store'])
        ->middleware(['spa.browser:tickets', 'throttle:ticket-action']);

    Route::get('tickets/{ticket}/attachments', [TicketAttachmentController::class, 'index'])
        ->middleware('spa.browser:tickets');
    Route::post('tickets/{ticket}/attachments', [TicketAttachmentController::class, 'store'])
        ->middleware(['spa.browser:tickets', 'throttle:ticket-upload']);
    Route::get('tickets/{ticket}/attachments/{attachment}/download', [TicketAttachmentController::class, 'download'])
        ->middleware('spa.browser:tickets');
});
