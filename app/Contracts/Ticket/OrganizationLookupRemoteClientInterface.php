<?php

namespace App\Contracts\Ticket;

/**
 * Provider-agnostic remote transport for organization lookup / verify.
 *
 * Implementations must not invent CA Cloud Desk request/response fields.
 * Until official API documentation is available, the default client refuses
 * remote calls rather than fabricating organizations or emails.
 */
interface OrganizationLookupRemoteClientInterface
{
    /**
     * @return list<array{organization_number: string, organization_name: string}>
     */
    public function lookupOrganizations(string $mobileNumber): array;

    /**
     * @return array{
     *     organization_number: string,
     *     organization_name: string,
     *     email: string
     * }
     */
    public function verifyOrganization(string $mobileNumber, string $organizationNumber): array;
}
