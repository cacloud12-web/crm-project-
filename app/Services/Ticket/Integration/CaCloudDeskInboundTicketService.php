<?php

namespace App\Services\Ticket\Integration;

use App\Models\SupportTicket;
use App\Models\TicketSyncLog;
use App\Services\Ticket\TicketNumberService;
use App\Services\Ticket\TicketStatusHistoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CaCloudDeskInboundTicketService
{
    public function __construct(
        private readonly TicketNumberService $ticketNumberService,
        private readonly TicketStatusHistoryService $statusHistoryService,
    ) {}

    /**
     * Upsert a LawSeva/portal ticket into CRM (idempotent on source + external id).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rawPayload
     * @return array{ticket: SupportTicket, created: bool}
     */
    public function upsertFromExternal(array $data, array $rawPayload = []): array
    {
        $externalId = trim((string) ($data['external_ticket_id'] ?? ''));
        if ($externalId === '') {
            throw ValidationException::withMessages([
                'external_ticket_id' => ['external_ticket_id is required.'],
            ]);
        }

        return DB::transaction(function () use ($data, $rawPayload, $externalId) {
            $existing = SupportTicket::query()
                ->where('source_system', SupportTicket::SOURCE_CA_CLOUD_DESK)
                ->where('external_ticket_id', $externalId)
                ->first();

            $remarks = $data['admin_remarks'] ?? null;
            if (filled($data['category'] ?? null)) {
                $categoryLine = 'Category: '.$data['category'];
                $remarks = filled($remarks) ? ($remarks."\n".$categoryLine) : $categoryLine;
            }

            $payload = [
                'customer_name' => $data['customer_name'],
                'organization_number' => (string) $data['organization_number'],
                'organization_name' => $data['organization_name'],
                'raised_by_name' => $data['raised_by_name'] ?? null,
                'mobile_number' => $data['mobile_number'],
                'email' => $data['email'] ?? null,
                'problem_type' => $data['problem_type'],
                'priority' => $data['priority'] ?? 'normal',
                'status' => $data['status'] ?? 'open',
                'description' => $data['description'],
                'admin_remarks' => $remarks,
                'created_via' => SupportTicket::CREATED_VIA_CA_CLOUD_DESK,
                'source_system' => SupportTicket::SOURCE_CA_CLOUD_DESK,
                'external_ticket_id' => $externalId,
                'external_updated_at' => $data['external_updated_at']
                    ?? $data['modified_at']
                    ?? $data['created_at']
                    ?? now(),
                'external_payload' => $rawPayload !== [] ? $rawPayload : $data,
                'sync_status' => 'synced',
                'synced_at' => now(),
                'acknowledged_at' => now(),
                'email_verification_status' => filled($data['email'] ?? null) ? 'verified' : 'skipped',
                'verification_source' => 'ca_cloud_desk_inbound',
                'customer_email_verified_at' => filled($data['email'] ?? null) ? now() : null,
            ];

            if ($existing) {
                $existing->fill($payload);
                $existing->save();

                $this->writeSyncLog($existing, 'updated', $rawPayload);

                return [
                    'ticket' => $existing->fresh(),
                    'created' => false,
                ];
            }

            $identifiers = $this->ticketNumberService->allocate();
            $ticket = SupportTicket::create(array_merge($payload, [
                'serial_number' => $identifiers['serial_number'],
                'ticket_number' => $identifiers['ticket_number'],
            ]));

            $this->statusHistoryService->recordCreation($ticket, null, 'Inbound ticket from LawSeva / CA Cloud Desk');
            $this->writeSyncLog($ticket, 'created', $rawPayload);

            return [
                'ticket' => $ticket->fresh(),
                'created' => true,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     */
    private function writeSyncLog(SupportTicket $ticket, string $result, array $rawPayload): void
    {
        TicketSyncLog::query()->create([
            'support_ticket_id' => $ticket->id,
            'sync_operation' => TicketSyncLog::OPERATION_TICKET_INBOUND,
            'direction' => 'inbound',
            'source_system' => SupportTicket::SOURCE_CA_CLOUD_DESK,
            'status' => 'success',
            'external_ticket_id' => $ticket->external_ticket_id,
            'organization_number' => $ticket->organization_number,
            'mobile_number' => $ticket->mobile_number,
            'request_payload' => $rawPayload,
            'response_payload' => [
                'crm_ticket_id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'result' => $result,
            ],
            'error_message' => 'Inbound ticket '.$result,
            'processed_at' => now(),
        ]);
    }
}
