<?php

namespace Tests\Feature;

use App\Contracts\Ticket\OrganizationLookupRemoteClientInterface;
use App\Exceptions\Ticket\CaCloudDeskIntegrationException;
use App\Models\TicketOrganizationLookup;
use App\Models\TicketSyncLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Support\CrmTestAccounts;
use Tests\TestCase;

class TicketOrganizationLookupTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ca_cloud_desk_integration.enabled' => false,
            'ca_cloud_desk_integration.base_url' => null,
            'ca_cloud_desk_integration.api_token' => null,
            'ca_cloud_desk_integration.lookup_endpoint' => null,
            'ca_cloud_desk_integration.verify_endpoint' => null,
        ]);
    }

    private function enableIntegrationConfig(): void
    {
        config([
            'ca_cloud_desk_integration.enabled' => true,
            'ca_cloud_desk_integration.base_url' => 'https://example.invalid',
            'ca_cloud_desk_integration.api_token' => 'test-token',
            'ca_cloud_desk_integration.lookup_endpoint' => '/placeholder-lookup',
            'ca_cloud_desk_integration.verify_endpoint' => '/placeholder-verify',
        ]);
    }

    /**
     * @param  list<array{organization_number: string, organization_name: string}>  $organizations
     */
    private function bindSuccessfulRemote(
        array $organizations,
        ?array $verifyResult = null,
    ): void {
        $this->app->instance(OrganizationLookupRemoteClientInterface::class, new class($organizations, $verifyResult) implements OrganizationLookupRemoteClientInterface
        {
            /**
             * @param  list<array{organization_number: string, organization_name: string}>  $organizations
             * @param  array{organization_number: string, organization_name: string, email: string}|null  $verifyResult
             */
            public function __construct(
                private readonly array $organizations,
                private readonly ?array $verifyResult,
            ) {}

            public function lookupOrganizations(string $mobileNumber): array
            {
                return $this->organizations;
            }

            public function verifyOrganization(string $mobileNumber, string $organizationNumber): array
            {
                if ($this->verifyResult === null) {
                    throw new CaCloudDeskIntegrationException('Verification failed.', 422);
                }

                return $this->verifyResult;
            }
        });
    }

    private function bindFailingRemote(string $message = 'Upstream lookup failed.', int $status = 500): void
    {
        $this->app->instance(OrganizationLookupRemoteClientInterface::class, new class($message, $status) implements OrganizationLookupRemoteClientInterface
        {
            public function __construct(
                private readonly string $message,
                private readonly int $status,
            ) {}

            public function lookupOrganizations(string $mobileNumber): array
            {
                throw new CaCloudDeskIntegrationException($this->message, $this->status);
            }

            public function verifyOrganization(string $mobileNumber, string $organizationNumber): array
            {
                throw new CaCloudDeskIntegrationException($this->message, $this->status);
            }
        });
    }

    public function test_guest_cannot_lookup_organizations(): void
    {
        $this->getJson('/ticket-organizations?mobile_number=9876543210')->assertUnauthorized();
        $this->postJson('/ticket-organizations/verify', [])->assertUnauthorized();
    }

    public function test_integration_disabled_returns_503(): void
    {
        $admin = CrmTestAccounts::admin();

        $this->actingAs($admin)
            ->getJson('/ticket-organizations?mobile_number=9876543210')
            ->assertStatus(503)
            ->assertJsonPath('message', 'CA Cloud Desk organization lookup is not configured yet.');

        $this->assertDatabaseHas('ticket_sync_logs', [
            'sync_operation' => TicketSyncLog::OPERATION_ORGANIZATION_LOOKUP,
            'status' => 'failed',
            'mobile_number' => '9876543210',
        ]);
    }

    public function test_configuration_missing_returns_503(): void
    {
        config([
            'ca_cloud_desk_integration.enabled' => true,
            'ca_cloud_desk_integration.base_url' => 'https://example.invalid',
            'ca_cloud_desk_integration.api_token' => null,
            'ca_cloud_desk_integration.lookup_endpoint' => '/lookup',
            'ca_cloud_desk_integration.verify_endpoint' => '/verify',
        ]);

        $admin = CrmTestAccounts::admin();

        $this->actingAs($admin)
            ->getJson('/ticket-organizations?mobile_number=9876543210')
            ->assertStatus(503)
            ->assertJsonPath('message', 'CA Cloud Desk organization lookup is not configured yet.');
    }

    public function test_invalid_mobile_returns_422(): void
    {
        $admin = CrmTestAccounts::admin();

        $this->actingAs($admin)
            ->getJson('/ticket-organizations?mobile_number=12')
            ->assertStatus(422);

        $this->actingAs($admin)
            ->getJson('/ticket-organizations')
            ->assertStatus(422);
    }

    public function test_cache_hit_returns_organizations_without_email(): void
    {
        $admin = CrmTestAccounts::admin();
        $correlationId = (string) Str::uuid();

        TicketOrganizationLookup::create([
            'mobile_number' => '9876543210',
            'organizations_payload' => [
                [
                    'organization_number' => 'ORG-1',
                    'organization_name' => 'Alpha Org',
                    'email' => 'secret@should-not-leak.test',
                ],
            ],
            'lookup_status' => 'success',
            'verification_status' => 'pending',
            'verified_email' => null,
            'expires_at' => now()->addMinutes(20),
            'lookup_source' => 'crm_cache',
            'correlation_id' => $correlationId,
            'requested_by_user_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/ticket-organizations?mobile_number=9876543210')
            ->assertOk()
            ->assertJsonPath('data.cached', true)
            ->assertJsonPath('data.correlation_id', $correlationId)
            ->assertJsonPath('data.organizations.0.organization_number', 'ORG-1')
            ->assertJsonPath('data.organizations.0.organization_name', 'Alpha Org');

        $payload = $response->json('data');
        $this->assertArrayNotHasKey('email', $payload['organizations'][0]);
        $this->assertStringNotContainsString('secret@should-not-leak.test', $response->getContent());

        $this->assertDatabaseHas('ticket_sync_logs', [
            'sync_operation' => TicketSyncLog::OPERATION_ORGANIZATION_LOOKUP,
            'status' => 'success',
            'correlation_id' => $correlationId,
        ]);
    }

    public function test_cache_miss_uses_remote_and_generates_correlation_id(): void
    {
        $this->enableIntegrationConfig();
        $this->bindSuccessfulRemote([
            ['organization_number' => 'ORG-55', 'organization_name' => 'Remote Org'],
        ]);

        $admin = CrmTestAccounts::admin();

        $response = $this->actingAs($admin)
            ->getJson('/ticket-organizations?mobile_number=9988776655')
            ->assertOk()
            ->assertJsonPath('data.cached', false)
            ->assertJsonPath('data.organizations.0.organization_number', 'ORG-55');

        $correlationId = $response->json('data.correlation_id');
        $this->assertTrue(Str::isUuid($correlationId));

        $this->assertDatabaseHas('ticket_organization_lookups', [
            'correlation_id' => $correlationId,
            'mobile_number' => '9988776655',
            'lookup_status' => 'success',
            'verification_status' => 'pending',
            'verified_email' => null,
        ]);
    }

    public function test_lookup_failure_is_logged(): void
    {
        $this->enableIntegrationConfig();
        $this->bindFailingRemote('Upstream lookup failed.', 502);

        $admin = CrmTestAccounts::admin();

        $this->actingAs($admin)
            ->getJson('/ticket-organizations?mobile_number=9123456780')
            ->assertStatus(502)
            ->assertJsonPath('message', 'Upstream lookup failed.');

        $this->assertDatabaseHas('ticket_sync_logs', [
            'sync_operation' => TicketSyncLog::OPERATION_ORGANIZATION_LOOKUP,
            'status' => 'failed',
            'mobile_number' => '9123456780',
            'error_message' => 'Upstream lookup failed.',
        ]);
    }

    public function test_verification_failure_clears_email(): void
    {
        $this->enableIntegrationConfig();
        $this->bindFailingRemote('Organization not found.', 422);

        $admin = CrmTestAccounts::admin();
        $correlationId = (string) Str::uuid();

        TicketOrganizationLookup::create([
            'mobile_number' => '9876543210',
            'organizations_payload' => [
                ['organization_number' => 'ORG-1', 'organization_name' => 'Alpha Org'],
            ],
            'lookup_status' => 'success',
            'verification_status' => 'pending',
            'verified_email' => 'temp@example.test',
            'expires_at' => now()->addMinutes(20),
            'lookup_source' => 'ca_cloud_desk',
            'correlation_id' => $correlationId,
            'requested_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->postJson('/ticket-organizations/verify', [
                'mobile_number' => '9876543210',
                'organization_number' => 'ORG-1',
                'correlation_id' => $correlationId,
            ])
            ->assertStatus(422);

        $lookup = TicketOrganizationLookup::query()->where('correlation_id', $correlationId)->first();
        $this->assertSame('failed', $lookup->verification_status);
        $this->assertNull($lookup->verified_email);
        $this->assertNull($lookup->verified_at);

        $this->assertDatabaseHas('ticket_sync_logs', [
            'sync_operation' => TicketSyncLog::OPERATION_ORGANIZATION_VERIFY,
            'status' => 'failed',
            'correlation_id' => $correlationId,
        ]);
    }

    public function test_verification_success_stores_email_and_correlation(): void
    {
        $this->enableIntegrationConfig();
        $this->bindSuccessfulRemote(
            [['organization_number' => 'ORG-9', 'organization_name' => 'Verified Co']],
            [
                'organization_number' => 'ORG-9',
                'organization_name' => 'Verified Co',
                'email' => 'verified.client@example.test',
            ],
        );

        $admin = CrmTestAccounts::admin();
        $correlationId = (string) Str::uuid();

        TicketOrganizationLookup::create([
            'mobile_number' => '9876543210',
            'organizations_payload' => [
                ['organization_number' => 'ORG-9', 'organization_name' => 'Verified Co'],
            ],
            'lookup_status' => 'success',
            'verification_status' => 'pending',
            'verified_email' => null,
            'expires_at' => now()->addMinutes(20),
            'lookup_source' => 'ca_cloud_desk',
            'correlation_id' => $correlationId,
            'requested_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->postJson('/ticket-organizations/verify', [
                'mobile_number' => '9876543210',
                'organization_number' => 'ORG-9',
                'correlation_id' => $correlationId,
                // Browser-supplied identity fields must be ignored / prohibited.
                'email' => 'attacker@evil.test',
                'organization_name' => 'Hacked Name',
            ])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson('/ticket-organizations/verify', [
                'mobile_number' => '9876543210',
                'organization_number' => 'ORG-9',
                'correlation_id' => $correlationId,
            ])
            ->assertOk()
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.verification_status', 'verified')
            ->assertJsonPath('data.email', 'verified.client@example.test')
            ->assertJsonPath('data.organization_number', 'ORG-9')
            ->assertJsonPath('data.organization_name', 'Verified Co')
            ->assertJsonPath('data.correlation_id', $correlationId);

        $this->assertDatabaseHas('ticket_organization_lookups', [
            'correlation_id' => $correlationId,
            'verification_status' => 'verified',
            'verified_email' => 'verified.client@example.test',
            'organization_number' => 'ORG-9',
            'organization_name' => 'Verified Co',
        ]);
    }

    public function test_expired_verification_is_rejected(): void
    {
        $admin = CrmTestAccounts::admin();
        $correlationId = (string) Str::uuid();

        TicketOrganizationLookup::create([
            'mobile_number' => '9876543210',
            'organization_number' => 'ORG-1',
            'organization_name' => 'Alpha Org',
            'organizations_payload' => [
                ['organization_number' => 'ORG-1', 'organization_name' => 'Alpha Org'],
            ],
            'lookup_status' => 'success',
            'verification_status' => 'verified',
            'verified_email' => 'old@example.test',
            'verified_at' => now()->subHour(),
            'expires_at' => now()->subMinute(),
            'lookup_source' => 'ca_cloud_desk',
            'correlation_id' => $correlationId,
            'requested_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->postJson('/ticket-organizations/verify', [
                'mobile_number' => '9876543210',
                'organization_number' => 'ORG-1',
                'correlation_id' => $correlationId,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Organization verification has expired. Please verify again.');

        $lookup = TicketOrganizationLookup::query()->where('correlation_id', $correlationId)->first();
        $this->assertSame('failed', $lookup->verification_status);
        $this->assertNull($lookup->verified_email);
    }

    public function test_email_hidden_before_verification_on_cache_miss_lookup(): void
    {
        $this->enableIntegrationConfig();
        $this->bindSuccessfulRemote([
            [
                'organization_number' => 'ORG-77',
                'organization_name' => 'Leak Check Org',
                'email' => 'must-not-appear@example.test',
            ],
        ]);

        $admin = CrmTestAccounts::admin();

        $response = $this->actingAs($admin)
            ->getJson('/ticket-organizations?mobile_number=9000011122')
            ->assertOk();

        $org = $response->json('data.organizations.0');
        $this->assertSame('ORG-77', $org['organization_number']);
        $this->assertArrayNotHasKey('email', $org);
        $this->assertStringNotContainsString('must-not-appear@example.test', $response->getContent());

        $row = TicketOrganizationLookup::query()
            ->where('correlation_id', $response->json('data.correlation_id'))
            ->first();
        $this->assertNull($row->verified_email);
        $this->assertSame('pending', $row->verification_status);
    }

    public function test_cached_verification_success_does_not_require_remote(): void
    {
        $admin = CrmTestAccounts::admin();
        $correlationId = (string) Str::uuid();

        TicketOrganizationLookup::create([
            'mobile_number' => '9876543210',
            'organization_number' => 'ORG-1',
            'organization_name' => 'Alpha Org',
            'organizations_payload' => [
                ['organization_number' => 'ORG-1', 'organization_name' => 'Alpha Org'],
            ],
            'lookup_status' => 'success',
            'verification_status' => 'verified',
            'verified_email' => 'cached.verify@example.test',
            'verified_at' => now(),
            'expires_at' => now()->addMinutes(20),
            'lookup_source' => 'crm_cache',
            'correlation_id' => $correlationId,
            'requested_by_user_id' => $admin->id,
        ]);

        // Integration remains disabled — cached verification must still succeed from DB.
        $this->actingAs($admin)
            ->postJson('/ticket-organizations/verify', [
                'mobile_number' => '9876543210',
                'organization_number' => 'ORG-1',
                'correlation_id' => $correlationId,
            ])
            ->assertOk()
            ->assertJsonPath('data.email', 'cached.verify@example.test')
            ->assertJsonPath('data.verified', true);
    }
}
