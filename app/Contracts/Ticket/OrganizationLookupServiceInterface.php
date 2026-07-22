<?php

namespace App\Contracts\Ticket;

use App\Models\User;

interface OrganizationLookupServiceInterface
{
    public function isConfigured(): bool;

    /**
     * Lookup organizations linked to a mobile number.
     * Must not return client email at this stage.
     *
     * @return array{
     *     correlation_id: string,
     *     lookup_status: string,
     *     organizations: list<array<string, mixed>>
     * }
     */
    public function lookupByMobile(string $mobileNumber, ?User $user = null): array;

    /**
     * Verify mobile number + organization number and return verified email on success.
     *
     * @return array{
     *     correlation_id: string,
     *     verification_status: string,
     *     verified: bool,
     *     email: string|null
     * }
     */
    public function verifyOrganization(
        string $mobileNumber,
        string $organizationNumber,
        string $correlationId,
        ?User $user = null,
    ): array;
}
