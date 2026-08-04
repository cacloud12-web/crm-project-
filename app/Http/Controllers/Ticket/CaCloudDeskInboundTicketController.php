<?php

namespace App\Http\Controllers\Ticket;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ticket\InboundCaCloudDeskTicketRequest;
use App\Services\Ticket\Integration\CaCloudDeskInboundTicketService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CaCloudDeskInboundTicketController extends Controller
{
    public function __construct(
        private readonly CaCloudDeskInboundTicketService $inboundTicketService,
    ) {}

    /**
     * LawSeva / partner portal webhook: notify CRM when a ticket is created or updated.
     */
    public function store(InboundCaCloudDeskTicketRequest $request): JsonResponse
    {
        $result = $this->inboundTicketService->upsertFromExternal(
            $request->validated(),
            $request->all(),
        );

        $ticket = $result['ticket'];

        return ApiResponse::success([
            'created' => $result['created'],
            'crm_ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'serial_number' => $ticket->serial_number,
            'external_ticket_id' => $ticket->external_ticket_id,
            'status' => $ticket->status,
            'sync_status' => $ticket->sync_status,
        ], $result['created'] ? 'Ticket created in CRM' : 'Ticket updated in CRM', $result['created'] ? 201 : 200);
    }
}
