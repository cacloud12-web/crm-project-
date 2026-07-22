<?php

namespace App\Services\Ticket\Integration;

use App\Contracts\Ticket\OrganizationLookupRemoteClientInterface;
use App\Exceptions\Ticket\CaCloudDeskIntegrationException;
use App\Exceptions\Ticket\CaCloudDeskIntegrationNotConfiguredException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP transport scaffold for CA Cloud Desk.
 *
 * Does not invent request payloads or response field names. Remote calls are
 * refused until official API documentation is supplied and mapping is added.
 */
class CaCloudDeskHttpClient implements OrganizationLookupRemoteClientInterface
{
    public function lookupOrganizations(string $mobileNumber): array
    {
        $this->assertTransportConfigured();

        // Official request/response contract is not available yet — never invent orgs.
        $this->logSafe('ticket.integration.lookup_refused', [
            'reason' => 'api_contract_unavailable',
            'endpoint_configured' => filled(config('ca_cloud_desk_integration.lookup_endpoint')),
        ]);

        throw new CaCloudDeskIntegrationException(
            'CA Cloud Desk organization lookup API contract is not available yet.',
            503,
        );
    }

    public function verifyOrganization(string $mobileNumber, string $organizationNumber): array
    {
        $this->assertTransportConfigured();

        $this->logSafe('ticket.integration.verify_refused', [
            'reason' => 'api_contract_unavailable',
            'endpoint_configured' => filled(config('ca_cloud_desk_integration.verify_endpoint')),
        ]);

        throw new CaCloudDeskIntegrationException(
            'CA Cloud Desk organization verification API contract is not available yet.',
            503,
        );
    }

    /**
     * Future hook: build absolute URL from config only (no hardcoded paths).
     */
    protected function absoluteUrl(string $endpointKey): string
    {
        $base = rtrim((string) config('ca_cloud_desk_integration.base_url'), '/');
        $endpoint = (string) config("ca_cloud_desk_integration.{$endpointKey}");
        if ($endpoint === '') {
            throw new CaCloudDeskIntegrationNotConfiguredException;
        }

        if (str_starts_with($endpoint, 'http://') || str_starts_with($endpoint, 'https://')) {
            return $endpoint;
        }

        return $base.'/'.ltrim($endpoint, '/');
    }

    /**
     * Future hook: authenticated HTTP client using config timeout/retry.
     * Never logs Authorization / api_token.
     */
    protected function httpClient()
    {
        $timeout = (int) (config('ca_cloud_desk_integration.timeout')
            ?: config('ca_cloud_desk_integration.timeout_seconds', 20));
        $retries = max(0, (int) config('ca_cloud_desk_integration.retry_times', 2));
        $sleepMs = max(0, (int) config('ca_cloud_desk_integration.retry_sleep_ms', 500));
        $token = (string) config('ca_cloud_desk_integration.api_token');

        return Http::timeout($timeout)
            ->retry($retries, $sleepMs, function ($exception) {
                return $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && ($exception->response?->status() ?? 0) >= 500);
            })
            ->withToken($token)
            ->acceptJson();
    }

    protected function assertTransportConfigured(): void
    {
        if (! filter_var(config('ca_cloud_desk_integration.enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            throw new CaCloudDeskIntegrationNotConfiguredException;
        }

        foreach (['base_url', 'api_token', 'lookup_endpoint', 'verify_endpoint'] as $key) {
            if (! filled(config("ca_cloud_desk_integration.{$key}"))) {
                throw new CaCloudDeskIntegrationNotConfiguredException;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logSafe(string $message, array $context = []): void
    {
        unset($context['api_token'], $context['token'], $context['Authorization'], $context['authorization']);
        Log::info($message, $context);
    }
}
