<?php

namespace App\Http\Controllers\Ticket;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ticket\AssignTicketRequest;
use App\Http\Requests\Ticket\ChangeTicketStatusRequest;
use App\Http\Requests\Ticket\StoreSupportTicketRequest;
use App\Http\Requests\Ticket\UpdateSupportTicketRequest;
use App\Http\Resources\SupportTicketResource;
use App\Http\Resources\TicketStatusHistoryResource;
use App\Models\SupportTicket;
use App\Services\Ticket\SupportTicketService;
use App\Support\ApiResponse;
use App\Support\Listing\ListingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportTicketController extends Controller
{
    public function __construct(
        private readonly SupportTicketService $supportTicketService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SupportTicket::class);

        $result = $this->supportTicketService->search($request->query(), $request->user());

        return ListingResponse::from($result, SupportTicketResource::class, 'Tickets loaded');
    }

    public function metadata(): JsonResponse
    {
        $this->authorize('viewAny', SupportTicket::class);

        return ApiResponse::success(
            $this->supportTicketService->metadata(),
            'Ticket metadata loaded',
        );
    }

    public function store(StoreSupportTicketRequest $request): JsonResponse
    {
        $this->authorize('create', SupportTicket::class);

        try {
            $ticket = $this->supportTicketService->create(
                $request->validated(),
                $request->user(),
            );

            return ApiResponse::created(
                new SupportTicketResource($ticket),
                'Ticket created successfully',
            );
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    public function show(SupportTicket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        $ticket = $this->supportTicketService->find($ticket->id, auth()->user());

        return ApiResponse::success(new SupportTicketResource($ticket));
    }

    public function update(UpdateSupportTicketRequest $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorize('update', $ticket);

        try {
            $ticket = $this->supportTicketService->update(
                $ticket,
                $request->validated(),
                $request->user(),
            );

            return ApiResponse::success(
                new SupportTicketResource($ticket),
                'Ticket updated successfully',
            );
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    public function changeStatus(ChangeTicketStatusRequest $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorize('update', $ticket);

        try {
            $ticket = $this->supportTicketService->changeStatus(
                $ticket,
                (string) $request->validated('status'),
                $request->user(),
                $request->validated('notes'),
            );

            return ApiResponse::success(
                new SupportTicketResource($ticket),
                'Ticket status updated successfully',
            );
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    public function assign(AssignTicketRequest $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorize('assign', $ticket);

        try {
            $ticket = $this->supportTicketService->assign(
                $ticket,
                (int) $request->validated('assigned_to_employee_id'),
                $request->user(),
            );

            return ApiResponse::success(
                new SupportTicketResource($ticket),
                'Ticket assigned successfully',
            );
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    public function destroy(SupportTicket $ticket): JsonResponse
    {
        $this->authorize('delete', $ticket);

        $this->supportTicketService->delete($ticket, auth()->user());

        return ApiResponse::success(null, 'Ticket deleted successfully');
    }

    public function history(SupportTicket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        $this->supportTicketService->find($ticket->id, auth()->user());
        $histories = $ticket->statusHistories()
            ->with([
                'changedByUser:id,name',
                'fromAssignee:employee_id,name',
                'toAssignee:employee_id,name',
            ])
            ->get();

        return ApiResponse::success(
            TicketStatusHistoryResource::collection($histories),
            'Ticket status history loaded',
        );
    }
}
