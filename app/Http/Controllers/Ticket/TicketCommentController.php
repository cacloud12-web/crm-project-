<?php

namespace App\Http\Controllers\Ticket;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ticket\StoreTicketCommentRequest;
use App\Http\Resources\TicketCommentResource;
use App\Models\SupportTicket;
use App\Services\Ticket\TicketCommentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TicketCommentController extends Controller
{
    public function __construct(
        private readonly TicketCommentService $ticketCommentService,
    ) {}

    public function index(SupportTicket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        $comments = $this->ticketCommentService->listForTicket($ticket, auth()->user());

        return ApiResponse::success(
            TicketCommentResource::collection($comments),
            'Ticket comments loaded',
        );
    }

    public function store(StoreTicketCommentRequest $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorize('update', $ticket);

        try {
            $comment = $this->ticketCommentService->create(
                $ticket,
                $request->validated(),
                $request->user(),
            );

            $message = ($comment->is_internal || $comment->visibility === 'internal')
                ? 'Internal note added successfully'
                : 'Reply added successfully';

            return ApiResponse::created(
                new TicketCommentResource($comment),
                $message,
            );
        } catch (AccessDeniedHttpException $e) {
            return ApiResponse::error($e->getMessage(), 403);
        }
    }
}
