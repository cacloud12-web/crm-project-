<?php

namespace App\Http\Controllers\Ticket;

use App\Exceptions\Ocr\OcrFileException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ticket\StoreTicketAttachmentRequest;
use App\Http\Resources\TicketAttachmentResource;
use App\Models\SupportTicket;
use App\Models\TicketAttachment;
use App\Services\Ticket\TicketAttachmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketAttachmentController extends Controller
{
    public function __construct(
        private readonly TicketAttachmentService $ticketAttachmentService,
    ) {}

    public function index(SupportTicket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        $attachments = $this->ticketAttachmentService->listForTicket($ticket, auth()->user());

        return ApiResponse::success(
            TicketAttachmentResource::collection($attachments),
            'Ticket attachments loaded',
        );
    }

    public function store(StoreTicketAttachmentRequest $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorize('update', $ticket);

        try {
            $commentId = $request->filled('ticket_comment_id')
                ? (int) $request->integer('ticket_comment_id')
                : null;

            $attachment = $this->ticketAttachmentService->store(
                $ticket,
                $request->file('attachment'),
                $request->user(),
                $commentId,
            );

            return ApiResponse::created(
                new TicketAttachmentResource($attachment),
                'Attachment uploaded successfully',
            );
        } catch (OcrFileException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    public function download(SupportTicket $ticket, TicketAttachment $attachment): StreamedResponse|JsonResponse
    {
        $this->authorize('downloadAttachment', $ticket);

        if ((int) $attachment->support_ticket_id !== (int) $ticket->id) {
            return ApiResponse::error('Attachment does not belong to this ticket.', 404);
        }

        return $this->ticketAttachmentService->download($attachment, auth()->user());
    }
}
