<?php

namespace App\Services\Ticket\Integration;

use App\Contracts\Ticket\OrganizationLookupRemoteClientInterface;
use App\Contracts\Ticket\OrganizationLookupServiceInterface;
use App\Exceptions\Ticket\CaCloudDeskIntegrationException;
use App\Exceptions\Ticket\CaCloudDeskIntegrationNotConfiguredException;
use App\Models\SupportTicket;
use App\Models\TicketOrganizationLookup;
use App\Models\TicketSyncLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class CaCloudDeskOrganizationLookupService implements OrganizationLookupServiceInterface
{
    public function __construct(
        private readonly OrganizationLookupRemoteClientInterface $remoteClient,
    ) {}

    public function isConfigured(): bool
    {
        if (! filter_var(config('ca_cloud_desk_integration.enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        foreach (['base_url', 'api_token', 'lookup_endpoint', 'verify_endpoint'] as $key) {
            if (! filled(config("ca_cloud_desk_integration.{$key}"))) {
                return false;
            }
        }

        return true;
    }

    public function lookupByMobile(string $mobileNumber, ?User $user = null): array
    {
        $started = microtime(true);
        $mobileNumber = $this->normalizeMobile($mobileNumber);
        $this->assertValidMobile($mobileNumber);

        $cached = $this->findValidLookupCache($mobileNumber);
        if ($cached !== null) {
            $organizations = $this->sanitizeOrganizations($cached->organizations_payload ?? []);

            $this->logOperation(
                TicketSyncLog::OPERATION_ORGANIZATION_LOOKUP,
                'inbound',
                (string) $cached->correlation_id,
                $mobileNumber,
                null,
                'success',
                null,
                $started,
                [
                    'cache' => 'hit',
                    'organization_count' => count($organizations),
                ],
            );

            return [
                'correlation_id' => (string) $cached->correlation_id,
                'lookup_status' => 'success',
                'cached' => true,
                'organizations' => $organizations,
            ];
        }

        if (! $this->isConfigured()) {
            $correlationId = (string) Str::uuid();
            $this->persistLookupFailureRow(
                $mobileNumber,
                $correlationId,
                $user,
                'not_configured',
            );
            $this->logOperation(
                TicketSyncLog::OPERATION_ORGANIZATION_LOOKUP,
                'outbound',
                $correlationId,
                $mobileNumber,
                null,
                'failed',
                'CA Cloud Desk organization lookup is not configured yet.',
                $started,
                ['cache' => 'miss'],
            );

            throw new CaCloudDeskIntegrationNotConfiguredException;
        }

        $correlationId = (string) Str::uuid();

        try {
            $organizations = $this->sanitizeOrganizations(
                $this->remoteClient->lookupOrganizations($mobileNumber),
            );

            TicketOrganizationLookup::create([
                'mobile_number' => $mobileNumber,
                'organization_number' => null,
                'organization_name' => null,
                'organizations_payload' => $organizations,
                'lookup_status' => 'success',
                'verification_status' => 'pending',
                'verified_email' => null,
                'verified_at' => null,
                'expires_at' => $this->cacheExpiresAt(),
                'lookup_source' => 'ca_cloud_desk',
                'correlation_id' => $correlationId,
                'requested_by_user_id' => $user?->id,
            ]);

            $this->logOperation(
                TicketSyncLog::OPERATION_ORGANIZATION_LOOKUP,
                'outbound',
                $correlationId,
                $mobileNumber,
                null,
                'success',
                null,
                $started,
                [
                    'cache' => 'miss',
                    'organization_count' => count($organizations),
                ],
            );

            return [
                'correlation_id' => $correlationId,
                'lookup_status' => 'success',
                'cached' => false,
                'organizations' => $organizations,
            ];
        } catch (CaCloudDeskIntegrationNotConfiguredException $e) {
            $this->persistLookupFailureRow($mobileNumber, $correlationId, $user, 'not_configured');
            $this->logOperation(
                TicketSyncLog::OPERATION_ORGANIZATION_LOOKUP,
                'outbound',
                $correlationId,
                $mobileNumber,
                null,
                'failed',
                $e->getMessage(),
                $started,
                ['cache' => 'miss'],
            );
            throw $e;
        } catch (Throwable $e) {
            $message = $e->getMessage() ?: 'Organization lookup failed.';
            $this->persistLookupFailureRow($mobileNumber, $correlationId, $user, 'failed');
            $this->logOperation(
                TicketSyncLog::OPERATION_ORGANIZATION_LOOKUP,
                'outbound',
                $correlationId,
                $mobileNumber,
                null,
                'failed',
                $message,
                $started,
                ['cache' => 'miss'],
            );

            if ($e instanceof CaCloudDeskIntegrationException) {
                throw $e;
            }

            throw new CaCloudDeskIntegrationException($message, 500, 0, $e);
        }
    }

    public function verifyOrganization(
        string $mobileNumber,
        string $organizationNumber,
        string $correlationId,
        ?User $user = null,
    ): array {
        $started = microtime(true);
        $mobileNumber = $this->normalizeMobile($mobileNumber);
        $organizationNumber = trim($organizationNumber);
        $correlationId = trim($correlationId);

        $this->assertValidMobile($mobileNumber);

        if ($organizationNumber === '') {
            throw new InvalidArgumentException('Organization number is required.');
        }

        if ($correlationId === '' || ! Str::isUuid($correlationId)) {
            throw new InvalidArgumentException('A valid verification correlation id is required.');
        }

        $lookup = TicketOrganizationLookup::query()
            ->where('correlation_id', $correlationId)
            ->first();

        if (! $lookup) {
            throw new InvalidArgumentException('Organization lookup session was not found. Please search again.');
        }

        if ($lookup->mobile_number !== $mobileNumber) {
            throw new InvalidArgumentException('Mobile number does not match the organization lookup session.');
        }

        if ($lookup->isExpired()) {
            $this->markVerificationFailed($lookup);
            $this->logOperation(
                TicketSyncLog::OPERATION_ORGANIZATION_VERIFY,
                'inbound',
                $correlationId,
                $mobileNumber,
                $organizationNumber,
                'failed',
                'Organization verification has expired. Please verify again.',
                $started,
            );

            throw new InvalidArgumentException('Organization verification has expired. Please verify again.');
        }

        // Trust database only — never browser-supplied org name / email.
        if (
            $lookup->isVerified()
            && $lookup->organization_number === $organizationNumber
            && filled($lookup->verified_email)
        ) {
            $this->logOperation(
                TicketSyncLog::OPERATION_ORGANIZATION_VERIFY,
                'inbound',
                $correlationId,
                $mobileNumber,
                $organizationNumber,
                'success',
                null,
                $started,
                ['cache' => 'hit'],
            );

            return $this->verificationSuccessPayload($lookup);
        }

        if (! $this->isConfigured()) {
            $this->markVerificationFailed($lookup);
            $this->logOperation(
                TicketSyncLog::OPERATION_ORGANIZATION_VERIFY,
                'outbound',
                $correlationId,
                $mobileNumber,
                $organizationNumber,
                'failed',
                'CA Cloud Desk organization lookup is not configured yet.',
                $started,
            );

            throw new CaCloudDeskIntegrationNotConfiguredException;
        }

        try {
            $remote = $this->remoteClient->verifyOrganization($mobileNumber, $organizationNumber);
            $email = trim((string) ($remote['email'] ?? ''));
            $remoteOrgNumber = trim((string) ($remote['organization_number'] ?? $organizationNumber));
            $remoteOrgName = trim((string) ($remote['organization_name'] ?? ''));

            if ($email === '' || $remoteOrgNumber === '' || $remoteOrgName === '') {
                throw new CaCloudDeskIntegrationException(
                    'Organization verification response was incomplete.',
                    502,
                );
            }

            // Prefer name from prior lookup payload when numbers match (still DB-sourced).
            $payloadName = $this->organizationNameFromPayload($lookup, $remoteOrgNumber);
            $orgName = $payloadName ?: $remoteOrgName;

            $lookup->forceFill([
                'organization_number' => $remoteOrgNumber,
                'organization_name' => $orgName,
                'verification_status' => 'verified',
                'verified_email' => $email,
                'verified_at' => now(),
                'expires_at' => $this->cacheExpiresAt(),
                'lookup_source' => 'ca_cloud_desk',
            ])->save();

            $this->logOperation(
                TicketSyncLog::OPERATION_ORGANIZATION_VERIFY,
                'outbound',
                $correlationId,
                $mobileNumber,
                $remoteOrgNumber,
                'success',
                null,
                $started,
                ['cache' => 'miss'],
            );

            return $this->verificationSuccessPayload($lookup->fresh());
        } catch (CaCloudDeskIntegrationNotConfiguredException $e) {
            $this->markVerificationFailed($lookup);
            $this->logOperation(
                TicketSyncLog::OPERATION_ORGANIZATION_VERIFY,
                'outbound',
                $correlationId,
                $mobileNumber,
                $organizationNumber,
                'failed',
                $e->getMessage(),
                $started,
            );
            throw $e;
        } catch (InvalidArgumentException $e) {
            $this->markVerificationFailed($lookup);
            $this->logOperation(
                TicketSyncLog::OPERATION_ORGANIZATION_VERIFY,
                'outbound',
                $correlationId,
                $mobileNumber,
                $organizationNumber,
                'failed',
                $e->getMessage(),
                $started,
            );
            throw $e;
        } catch (Throwable $e) {
            $this->markVerificationFailed($lookup);
            $message = $e->getMessage() ?: 'Organization verification failed.';
            $this->logOperation(
                TicketSyncLog::OPERATION_ORGANIZATION_VERIFY,
                'outbound',
                $correlationId,
                $mobileNumber,
                $organizationNumber,
                'failed',
                $message,
                $started,
            );

            if ($e instanceof CaCloudDeskIntegrationException) {
                throw $e;
            }

            throw new CaCloudDeskIntegrationException($message, 500, 0, $e);
        }
    }

    private function findValidLookupCache(string $mobileNumber): ?TicketOrganizationLookup
    {
        $lookup = TicketOrganizationLookup::query()
            ->where('mobile_number', $mobileNumber)
            ->where('lookup_status', 'success')
            ->whereNotNull('organizations_payload')
            ->orderByDesc('id')
            ->first();

        if (! $lookup || $lookup->isExpired()) {
            return null;
        }

        $organizations = $this->sanitizeOrganizations($lookup->organizations_payload ?? []);
        if ($organizations === []) {
            return null;
        }

        return $lookup;
    }

    /**
     * @param  mixed  $organizations
     * @return list<array{organization_number: string, organization_name: string}>
     */
    private function sanitizeOrganizations(mixed $organizations): array
    {
        if (! is_array($organizations)) {
            return [];
        }

        $clean = [];
        foreach ($organizations as $row) {
            if (! is_array($row)) {
                continue;
            }

            $number = trim((string) ($row['organization_number'] ?? $row['number'] ?? ''));
            $name = trim((string) ($row['organization_name'] ?? $row['name'] ?? ''));
            if ($number === '' || $name === '') {
                continue;
            }

            // Never expose email (or aliases) before verification.
            $clean[] = [
                'organization_number' => $number,
                'organization_name' => $name,
            ];
        }

        return array_values($clean);
    }

    private function organizationNameFromPayload(TicketOrganizationLookup $lookup, string $organizationNumber): ?string
    {
        foreach ($this->sanitizeOrganizations($lookup->organizations_payload ?? []) as $org) {
            if ($org['organization_number'] === $organizationNumber) {
                return $org['organization_name'];
            }
        }

        return null;
    }

    /**
     * @return array{
     *     correlation_id: string,
     *     verification_status: string,
     *     verified: bool,
     *     email: string|null,
     *     verified_email: string|null,
     *     organization_number: string|null,
     *     organization_name: string|null,
     *     verified_at: string|null
     * }
     */
    private function verificationSuccessPayload(TicketOrganizationLookup $lookup): array
    {
        return [
            'correlation_id' => (string) $lookup->correlation_id,
            'verification_status' => 'verified',
            'verified' => true,
            'email' => $lookup->verified_email,
            'verified_email' => $lookup->verified_email,
            'organization_number' => $lookup->organization_number,
            'organization_name' => $lookup->organization_name,
            'verified_at' => optional($lookup->verified_at)?->toIso8601String(),
        ];
    }

    private function markVerificationFailed(TicketOrganizationLookup $lookup): void
    {
        $lookup->forceFill([
            'verification_status' => 'failed',
            'verified_email' => null,
            'verified_at' => null,
        ])->save();
    }

    private function persistLookupFailureRow(
        string $mobileNumber,
        string $correlationId,
        ?User $user,
        string $lookupStatus,
    ): void {
        TicketOrganizationLookup::create([
            'mobile_number' => $mobileNumber,
            'organizations_payload' => [],
            'lookup_status' => $lookupStatus,
            'verification_status' => 'pending',
            'verified_email' => null,
            'verified_at' => null,
            'expires_at' => $this->cacheExpiresAt(),
            'lookup_source' => 'ca_cloud_desk',
            'correlation_id' => $correlationId,
            'requested_by_user_id' => $user?->id,
        ]);
    }

    private function cacheExpiresAt(): \Illuminate\Support\Carbon
    {
        return now()->addMinutes((int) config('crm_tickets.organization_lookup_cache_ttl_minutes', 15));
    }

    private function normalizeMobile(string $mobileNumber): string
    {
        $digits = preg_replace('/\D+/', '', $mobileNumber) ?? '';

        return $digits !== '' ? $digits : trim($mobileNumber);
    }

    private function assertValidMobile(string $mobileNumber): void
    {
        if ($mobileNumber === '' || strlen($mobileNumber) < 8 || strlen($mobileNumber) > 15) {
            throw new InvalidArgumentException('Enter a valid mobile number.');
        }

        if (! preg_match('/^\d+$/', $mobileNumber)) {
            throw new InvalidArgumentException('Enter a valid mobile number.');
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function logOperation(
        string $operation,
        string $direction,
        string $correlationId,
        string $mobileNumber,
        ?string $organizationNumber,
        string $status,
        ?string $errorMessage,
        float $startedAt,
        array $meta = [],
    ): void {
        $processingMs = (int) round((microtime(true) - $startedAt) * 1000);

        Log::info('ticket.integration.operation', [
            'operation' => $operation,
            'direction' => $direction,
            'correlation_id' => $correlationId,
            'mobile_number' => $mobileNumber,
            'organization_number' => $organizationNumber,
            'status' => $status,
            'processing_time_ms' => $processingMs,
            // Never log api tokens / Authorization / verified email.
        ]);

        TicketSyncLog::create([
            'sync_operation' => $operation,
            'direction' => $direction,
            'source_system' => SupportTicket::SOURCE_CA_CLOUD_DESK,
            'correlation_id' => $correlationId,
            'mobile_number' => $mobileNumber,
            'organization_number' => $organizationNumber,
            'status' => $status,
            'error_message' => $errorMessage,
            'processed_at' => now(),
            'response_payload' => array_merge($meta, [
                'processing_time_ms' => $processingMs,
            ]),
        ]);
    }
}
