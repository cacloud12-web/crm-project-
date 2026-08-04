<?php

namespace App\Contracts\Ticket;

/**
 * Provider-agnostic remote transport for organization lookup / verify.
 *
 * LawSeva external auth mapping (X-Api-Key):
 *   lookupOrganizations → GET auth_organizations
 *   verifyOrganization  → GET auth_employee (organization + username/mobile)
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
     *     email: string,
     *     partner_id?: int|null,
     *     partner_name?: string|null,
     *     partner_email?: string|null,
     *     partner_phone?: string|null
     * }
     */
    public function verifyOrganization(string $mobileNumber, string $organizationNumber): array;
}
