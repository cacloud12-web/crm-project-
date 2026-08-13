<?php

namespace App\Services\Ticket\Integration;

use App\Exceptions\Ticket\CaCloudDeskIntegrationException;
use App\Exceptions\Ticket\CaCloudDeskIntegrationNotConfiguredException;
use App\Models\SupportTicket;
use App\Models\TicketOrganizationLookup;
use App\Models\TicketSyncLog;
use Illuminate\Support\Facades\Log;
use Throwable;

class CaCloudDeskOutboundTicketService
{
    public function __construct(
        private readonly CaCloudDeskHttpClient $httpClient,
    ) {}

    /**
     * Push a CRM-created ticket to LawSeva auth_ticket after the local row is saved.
     * Failures never delete the CRM ticket.
     */
    public function pushAfterCrmCreate(SupportTicket $ticket): SupportTicket
    {
        if (! $this->shouldPush($ticket)) {
            return $ticket;
        }

        $ticket = SupportTicket::query()->find($ticket->id) ?? $ticket;

        if ($this->alreadySynced($ticket)) {
            return $ticket->fresh();
        }

        $lookup = $this->resolveLookup($ticket);
        $partnerId = $lookup?->lawsevaPartnerId();
        $organizationId = $this->resolveOrganizationId($ticket);

        if ($organizationId === null || ! $partnerId) {
            $this->markFailed($ticket, 'Missing verified organization or partner id for CA Cloud Desk sync.');
            $this->writeSyncLog($ticket, $this->idempotencyKey($ticket), null, null, 'failed', 'Missing verified organization or partner id for CA Cloud Desk sync.', null, []);

            return $ticket->fresh();
        }

        if (! $this->isIntegrationEnabled()) {
            $this->writeSyncLog($ticket, $this->idempotencyKey($ticket), null, null, 'pending', 'CA Cloud Desk integration is not configured.', null, []);

            return $ticket->fresh();
        }

        $requestBody = [
            'organization' => $organizationId,
            'partner' => $partnerId,
            'category' => $this->mapProblemTypeToCategory($ticket->problem_type),
            'description' => $this->formatDescription((string) $ticket->description),
            'documents' => [],
        ];

        try {
            $response = $this->httpClient->createAuthTicket($requestBody);
            $externalId = $this->extractExternalTicketId($response);

            if ($externalId === null) {
                throw new CaCloudDeskIntegrationException(
                    'CA Cloud Desk ticket create response did not include an external ticket id.',
                    502,
                );
            }

            $ticket->update([
                'external_ticket_id' => $externalId,
                'sync_status' => 'synced',
                'synced_at' => now(),
                'external_updated_at' => now(),
                'external_payload' => $response,
            ]);

            $this->writeSyncLog(
                $ticket->fresh(),
                $this->idempotencyKey($ticket),
                $requestBody,
                $response,
                'success',
                null,
                200,
            );

            return $ticket->fresh();
        } catch (CaCloudDeskIntegrationNotConfiguredException $e) {
            $this->writeSyncLog(
                $ticket,
                $this->idempotencyKey($ticket),
                $requestBody,
                null,
                'pending',
                $this->safeErrorMessage($e->getMessage()),
                $e->httpStatus,
            );

            return $ticket->fresh();
        } catch (CaCloudDeskIntegrationException $e) {
            $this->markFailed($ticket, $this->safeErrorMessage($e->getMessage()));
            $this->writeSyncLog(
                $ticket->fresh(),
                $this->idempotencyKey($ticket),
                $requestBody,
                null,
                'failed',
                $this->safeErrorMessage($e->getMessage()),
                $e->httpStatus,
            );

            return $ticket->fresh();
        } catch (Throwable $e) {
            $message = $this->safeErrorMessage($e->getMessage() ?: 'CA Cloud Desk ticket sync failed.');
            $this->markFailed($ticket, $message);
            $this->writeSyncLog(
                $ticket->fresh(),
                $this->idempotencyKey($ticket),
                $requestBody,
                null,
                'failed',
                $message,
                null,
            );

            return $ticket->fresh();
        }
    }

    private function shouldPush(SupportTicket $ticket): bool
    {
        if ($ticket->source_system !== SupportTicket::SOURCE_CRM) {
            return false;
        }

        return ! in_array($ticket->created_via, [
            SupportTicket::CREATED_VIA_CA_CLOUD_DESK,
            SupportTicket::CREATED_VIA_API,
            SupportTicket::CREATED_VIA_SYSTEM,
        ], true);
    }

    private function alreadySynced(SupportTicket $ticket): bool
    {
        if (filled($ticket->external_ticket_id)) {
            return true;
        }

        return TicketSyncLog::query()
            ->where('support_ticket_id', $ticket->id)
            ->where('sync_operation', TicketSyncLog::OPERATION_TICKET_OUTBOUND)
            ->where('status', 'success')
            ->exists();
    }

    private function resolveLookup(SupportTicket $ticket): ?TicketOrganizationLookup
    {
        if (! filled($ticket->verification_correlation_id)) {
            return null;
        }

        return TicketOrganizationLookup::query()
            ->where('correlation_id', $ticket->verification_correlation_id)
            ->first();
    }

    private function resolveOrganizationId(SupportTicket $ticket): ?int
    {
        $organizationNumber = trim((string) $ticket->organization_number);
        if ($organizationNumber === '' || ! ctype_digit($organizationNumber)) {
            return null;
        }

        return (int) $organizationNumber;
    }

    /**
     * Prefer numeric LawSeva id (matches inbound webhook external_ticket_id).
     */
    public function extractExternalTicketId(array $response): ?string
    {
        if (isset($response['id']) && $response['id'] !== '' && $response['id'] !== null) {
            return (string) $response['id'];
        }

        if (isset($response['ticket_id']) && $response['ticket_id'] !== '' && $response['ticket_id'] !== null) {
            return (string) $response['ticket_id'];
        }

        return null;
    }

    public function mapProblemTypeToCategory(?string $problemType): string
    {
        $key = strtolower(trim((string) $problemType));
        $map = config('ca_cloud_desk_integration.problem_type_category_map', []);

        if ($key !== '' && isset($map[$key]) && filled($map[$key])) {
            return (string) $map[$key];
        }

        return (string) config('ca_cloud_desk_integration.default_category', 'Issue');
    }

    private function formatDescription(string $description): string
    {
        $trimmed = trim($description);
        if ($trimmed === '') {
            return '';
        }

        if (str_contains($trimmed, '<') && str_contains($trimmed, '>')) {
            return $trimmed;
        }

        return '<p>'.e($trimmed).'</p>';
    }

    private function isIntegrationEnabled(): bool
    {
        return filter_var(config('ca_cloud_desk_integration.enabled', false), FILTER_VALIDATE_BOOLEAN)
            && filled(config('ca_cloud_desk_integration.base_url'))
            && filled(config('ca_cloud_desk_integration.api_token'))
            && filled(config('ca_cloud_desk_integration.ticket_endpoint'));
    }

    private function idempotencyKey(SupportTicket $ticket): string
    {
        return 'ticket_outbound:'.$ticket->id;
    }

    private function markFailed(SupportTicket $ticket, string $message): void
    {
        $ticket->update(['sync_status' => 'failed']);
        Log::info('ticket.integration.outbound_failed', [
            'support_ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'message' => $this->safeErrorMessage($message),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $requestPayload
     * @param  array<string, mixed>|null  $responsePayload
     */
    private function writeSyncLog(
        SupportTicket $ticket,
        string $idempotencyKey,
        ?array $requestPayload,
        ?array $responsePayload,
        string $status,
        ?string $errorMessage,
        ?int $httpStatus,
        array $meta = [],
    ): void {
        $endpoint = (string) config('ca_cloud_desk_integration.ticket_endpoint', '');

        TicketSyncLog::query()->updateOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'support_ticket_id' => $ticket->id,
                'sync_operation' => TicketSyncLog::OPERATION_TICKET_OUTBOUND,
                'direction' => 'outbound',
                'source_system' => SupportTicket::SOURCE_CA_CLOUD_DESK,
                'correlation_id' => $ticket->verification_correlation_id,
                'mobile_number' => $ticket->mobile_number,
                'organization_number' => $ticket->organization_number,
                'endpoint' => $endpoint,
                'http_method' => 'POST',
                'http_status_code' => $httpStatus,
                'status' => $status,
                'external_ticket_id' => $ticket->external_ticket_id,
                'request_payload' => $requestPayload,
                'response_payload' => $responsePayload !== null
                    ? array_merge($responsePayload, $meta)
                    : ($meta !== [] ? $meta : null),
                'error_message' => $errorMessage !== null ? $this->safeErrorMessage($errorMessage) : null,
                'processed_at' => now(),
            ],
        );
    }

    private function safeErrorMessage(string $message): string
    {
        $message = preg_replace('/[A-Za-z0-9_\-]{20,}/', '[redacted]', $message) ?? $message;

        return str_replace(["\n", "\r"], ' ', trim($message));
    }
}
