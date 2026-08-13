<?php

namespace App\Services\Ticket\Integration;

use App\Contracts\Ticket\OrganizationLookupRemoteClientInterface;
use App\Exceptions\Ticket\CaCloudDeskIntegrationException;
use App\Exceptions\Ticket\CaCloudDeskIntegrationNotConfiguredException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for LawSeva / CA Cloud Desk external auth ticket APIs.
 *
 * Auth: X-Api-Key (EXTERNAL_AUTH_KEY). No JWT.
 *
 * Existing CRM lookup/verify adapters map onto:
 *   auth_organizations → list orgs
 *   auth_employee      → resolve partner by org + email/phone
 */
class CaCloudDeskHttpClient implements OrganizationLookupRemoteClientInterface
{
    /**
     * CRM adapter: list organizations for ticket org picker.
     * LawSeva auth_organizations returns all active orgs (mobile is unused by upstream).
     *
     * @return list<array{organization_number: string, organization_name: string}>
     */
    public function lookupOrganizations(string $mobileNumber): array
    {
        return $this->listOrganizations();
    }

    /**
     * CRM adapter: verify org + mobile by resolving the active partner (employee).
     *
     * @return array{
     *     organization_number: string,
     *     organization_name: string,
     *     email: string,
     *     partner_id: int|null,
     *     partner_name: string|null,
     *     partner_email: string|null,
     *     partner_phone: string|null
     * }
     */
    public function verifyOrganization(string $mobileNumber, string $organizationNumber): array
    {
        $employee = $this->lookupEmployee($organizationNumber, $mobileNumber);

        $orgId = (string) ($employee['organization'] ?? $organizationNumber);
        $orgName = trim((string) ($employee['organization_name'] ?? ''));
        $email = trim((string) ($employee['organization_email'] ?? ''));
        if ($email === '') {
            $email = trim((string) ($employee['email'] ?? ''));
        }

        if ($orgId === '' || $orgName === '' || $email === '') {
            throw new CaCloudDeskIntegrationException(
                'Organization verification response was incomplete.',
                502,
            );
        }

        return [
            'organization_number' => $orgId,
            'organization_name' => $orgName,
            'email' => $email,
            'partner_id' => isset($employee['employee']) ? (int) $employee['employee'] : null,
            'partner_name' => isset($employee['name']) ? (string) $employee['name'] : null,
            'partner_email' => isset($employee['email']) ? (string) $employee['email'] : null,
            'partner_phone' => isset($employee['phone']) ? (string) $employee['phone'] : null,
        ];
    }

    /**
     * GET auth_organizations — all active organizations.
     *
     * @return list<array{organization_number: string, organization_name: string}>
     */
    public function listOrganizations(): array
    {
        $this->assertTransportConfigured();

        $response = $this->send('GET', $this->absoluteUrl('organizations_endpoint'));
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new CaCloudDeskIntegrationException('Invalid organizations response from LawSeva.', 502);
        }

        // Some gateways wrap list payloads; accept either a bare array or { data: [] }.
        if (isset($payload['data']) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }

        $organizations = [];
        foreach ($payload as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = $row['id'] ?? $row['organization_number'] ?? null;
            $name = $row['name'] ?? $row['organization_name'] ?? null;
            if ($id === null || $id === '') {
                continue;
            }
            $organizations[] = [
                'organization_number' => (string) $id,
                'organization_name' => trim((string) ($name ?? '')),
            ];
        }

        $this->logSafe('ticket.integration.organizations_ok', [
            'count' => count($organizations),
        ]);

        return $organizations;
    }

    /**
     * GET auth_employee — active partner in an organization by email or phone.
     *
     * @return array<string, mixed>
     */
    public function lookupEmployee(string|int $organization, string $username): array
    {
        $this->assertTransportConfigured();

        $organization = trim((string) $organization);
        $username = trim($username);
        if ($organization === '') {
            throw new CaCloudDeskIntegrationException('organization is required', 400);
        }
        if ($username === '') {
            throw new CaCloudDeskIntegrationException('username is required', 400);
        }

        $url = $this->absoluteUrl('employee_endpoint');
        $separator = str_contains($url, '?') ? '&' : '?';
        $url .= $separator.http_build_query([
            'organization' => $organization,
            'username' => $username,
        ]);

        $response = $this->send('GET', $url);
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new CaCloudDeskIntegrationException('Invalid employee response from LawSeva.', 502);
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }

        if (! isset($payload['employee'])) {
            throw new CaCloudDeskIntegrationException('Employee not found', 400);
        }

        $this->logSafe('ticket.integration.employee_ok', [
            'organization' => $organization,
            'employee' => $payload['employee'] ?? null,
        ]);

        return $payload;
    }

    /**
     * POST auth_ticket — create LawSeva support ticket (org + partner in body).
     *
     * @param  array{
     *     organization: int|string,
     *     partner: int|string,
     *     category?: string,
     *     description?: string,
     *     documents?: array<int, mixed>,
     *     summery?: string
     * }  $body
     * @return array<string, mixed>
     */
    public function createAuthTicket(array $body): array
    {
        $this->assertTransportConfigured();

        if (! isset($body['organization'], $body['partner'])) {
            throw new CaCloudDeskIntegrationException('organization and partner are required', 400);
        }

        $payload = [
            'organization' => (int) $body['organization'],
            'partner' => (int) $body['partner'],
            'category' => (string) ($body['category'] ?? ''),
            'description' => (string) ($body['description'] ?? ''),
            'documents' => array_values(is_array($body['documents'] ?? null) ? $body['documents'] : []),
        ];
        if (isset($body['summery']) && $body['summery'] !== '') {
            $payload['summery'] = (string) $body['summery'];
        }

        $response = $this->send('POST', $this->absoluteUrl('ticket_endpoint'), $payload);
        $ticket = $response->json();

        if (! is_array($ticket)) {
            throw new CaCloudDeskIntegrationException('Invalid ticket create response from LawSeva.', 502);
        }

        if (isset($ticket['data']) && is_array($ticket['data'])) {
            $ticket = $ticket['data'];
        }

        $this->logSafe('ticket.integration.ticket_created', [
            'organization' => $payload['organization'],
            'partner' => $payload['partner'],
            'ticket_id' => $ticket['ticket_id'] ?? $ticket['id'] ?? null,
        ]);

        return $ticket;
    }

    protected function absoluteUrl(string $endpointKey): string
    {
        $base = rtrim((string) config('ca_cloud_desk_integration.base_url'), '/');
        $endpoint = (string) config("ca_cloud_desk_integration.{$endpointKey}");
        if ($endpoint === '') {
            // Fall back to legacy alias keys when needed.
            if ($endpointKey === 'organizations_endpoint') {
                $endpoint = (string) config('ca_cloud_desk_integration.lookup_endpoint');
            } elseif ($endpointKey === 'employee_endpoint') {
                $endpoint = (string) config('ca_cloud_desk_integration.verify_endpoint');
            }
        }

        if ($endpoint === '') {
            throw new CaCloudDeskIntegrationNotConfiguredException;
        }

        if (str_starts_with($endpoint, 'http://') || str_starts_with($endpoint, 'https://')) {
            return $endpoint;
        }

        return $base.'/'.ltrim($endpoint, '/');
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    protected function send(string $method, string $url, ?array $json = null): Response
    {
        try {
            $pending = $this->httpClient();
            $response = strtoupper($method) === 'POST'
                ? $pending->asJson()->post($url, $json ?? [])
                : $pending->get($url);
        } catch (ConnectionException $e) {
            $this->logSafe('ticket.integration.connection_failed', [
                'method' => $method,
                'message' => $e->getMessage(),
            ]);
            throw new CaCloudDeskIntegrationException(
                'Unable to reach CA Cloud Desk / LawSeva. Please try again.',
                503,
                0,
                $e,
            );
        } catch (RequestException $e) {
            $response = $e->response;
            if ($response === null) {
                throw new CaCloudDeskIntegrationException(
                    'CA Cloud Desk request failed.',
                    502,
                    0,
                    $e,
                );
            }
        }

        if ($response->successful()) {
            return $response;
        }

        $detail = $this->extractErrorDetail($response);
        $status = $response->status();

        $this->logSafe('ticket.integration.request_failed', [
            'method' => $method,
            'status' => $status,
            'detail' => $detail,
        ]);

        throw new CaCloudDeskIntegrationException(
            $detail !== '' ? $detail : 'CA Cloud Desk request failed.',
            $status >= 400 && $status < 600 ? $status : 502,
        );
    }

    protected function extractErrorDetail(Response $response): string
    {
        $json = $response->json();
        if (is_array($json)) {
            if (isset($json['detail']) && is_string($json['detail'])) {
                return trim($json['detail']);
            }
            if (isset($json['message']) && is_string($json['message'])) {
                return trim($json['message']);
            }
        }

        if ($response->status() === 401) {
            return 'Unauthorized';
        }

        return trim((string) $response->body());
    }

    protected function httpClient()
    {
        $timeout = (int) (config('ca_cloud_desk_integration.timeout')
            ?: config('ca_cloud_desk_integration.timeout_seconds', 20));
        $retries = max(0, (int) config('ca_cloud_desk_integration.retry_times', 2));
        $sleepMs = max(0, (int) config('ca_cloud_desk_integration.retry_sleep_ms', 500));
        $apiKey = (string) config('ca_cloud_desk_integration.api_token');
        $header = (string) config('ca_cloud_desk_integration.api_key_header', 'X-Api-Key');

        return Http::timeout($timeout)
            ->retry($retries, $sleepMs, function ($exception) {
                return $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && ($exception->response?->status() ?? 0) >= 500);
            })
            ->withHeaders([
                $header => $apiKey,
                'Accept' => 'application/json',
            ])
            ->acceptJson();
    }

    protected function assertTransportConfigured(): void
    {
        if (! filter_var(config('ca_cloud_desk_integration.enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            throw new CaCloudDeskIntegrationNotConfiguredException;
        }

        foreach (['base_url', 'api_token'] as $key) {
            if (! filled(config("ca_cloud_desk_integration.{$key}"))) {
                throw new CaCloudDeskIntegrationNotConfiguredException;
            }
        }

        $hasOrgs = filled(config('ca_cloud_desk_integration.organizations_endpoint'))
            || filled(config('ca_cloud_desk_integration.lookup_endpoint'));
        $hasEmployee = filled(config('ca_cloud_desk_integration.employee_endpoint'))
            || filled(config('ca_cloud_desk_integration.verify_endpoint'));

        if (! $hasOrgs || ! $hasEmployee) {
            throw new CaCloudDeskIntegrationNotConfiguredException;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logSafe(string $message, array $context = []): void
    {
        unset(
            $context['api_token'],
            $context['token'],
            $context['Authorization'],
            $context['authorization'],
            $context['X-Api-Key'],
            $context['EXTERNAL_AUTH_KEY'],
        );
        Log::info($message, $context);
    }
}
