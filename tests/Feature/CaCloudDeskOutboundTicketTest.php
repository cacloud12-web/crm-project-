<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\TicketOrganizationLookup;
use App\Models\TicketSyncLog;
use App\Models\User;
use App\Services\Ticket\Integration\CaCloudDeskOutboundTicketService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\CrmTestAccounts;
use Tests\TestCase;

class CaCloudDeskOutboundTicketTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ca_cloud_desk_integration.enabled' => false,
            'ca_cloud_desk_integration.base_url' => null,
            'ca_cloud_desk_integration.api_token' => null,
        ]);
    }

    private function enableIntegrationConfig(): void
    {
        config([
            'ca_cloud_desk_integration.enabled' => true,
            'ca_cloud_desk_integration.base_url' => 'https://partner.test',
            'ca_cloud_desk_integration.api_token' => 'test-token',
            'ca_cloud_desk_integration.api_key_header' => 'X-Api-Key',
            'ca_cloud_desk_integration.ticket_endpoint' => '/seva-api/v1/admin_settings/auth_ticket/',
            'ca_cloud_desk_integration.problem_type_category_map' => [
                'issue' => 'Issue',
                'improvement' => 'Improvement',
                'new_feature' => 'New Feature',
            ],
            'ca_cloud_desk_integration.default_category' => 'Issue',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createVerifiedLookup(User $user, array $overrides = []): TicketOrganizationLookup
    {
        return TicketOrganizationLookup::create(array_merge([
            'mobile_number' => '9876543210',
            'organization_number' => '3',
            'organization_name' => 'Verified Org Pvt Ltd',
            'organizations_payload' => [
                ['organization_number' => '3', 'organization_name' => 'Verified Org Pvt Ltd'],
                '_lawseva' => [
                    'partner_id' => 4,
                    'partner_name' => 'Partner User',
                    'partner_email' => 'partner@verified-org.test',
                    'partner_phone' => '9876543210',
                ],
            ],
            'lookup_status' => 'success',
            'verification_status' => 'verified',
            'verified_email' => 'client@verified-org.test',
            'verified_at' => now(),
            'expires_at' => now()->addMinutes(30),
            'lookup_source' => 'ca_cloud_desk',
            'correlation_id' => (string) Str::uuid(),
            'requested_by_user_id' => $user->id,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTicketPayload(TicketOrganizationLookup $lookup, array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Test Customer',
            'mobile_number' => $lookup->mobile_number,
            'verification_correlation_id' => $lookup->correlation_id,
            'problem_type' => 'issue',
            'priority' => 'normal',
            'description' => 'Unable to login to the portal',
        ], $overrides);
    }

    public function test_crm_create_pushes_ticket_to_ca_cloud_desk_and_stores_external_id(): void
    {
        $this->enableIntegrationConfig();

        Http::fake([
            'https://partner.test/seva-api/v1/admin_settings/auth_ticket/' => Http::response([
                'id' => 5678,
                'ticket_id' => 'TCK-5678',
                'organization' => 3,
                'partner' => 4,
            ], 200),
        ]);

        $employeeUser = CrmTestAccounts::employeeUser();
        $lookup = $this->createVerifiedLookup($employeeUser);

        $this->actingAs($employeeUser);

        $response = $this->postJson('/tickets', $this->createTicketPayload($lookup))
            ->assertCreated()
            ->assertJsonPath('data.external_ticket_id', '5678')
            ->assertJsonPath('data.sync_status', 'synced');

        $ticketId = (int) $response->json('data.id');

        $this->assertDatabaseHas('support_tickets', [
            'id' => $ticketId,
            'external_ticket_id' => '5678',
            'sync_status' => 'synced',
            'organization_number' => '3',
        ]);

        $this->assertDatabaseHas('ticket_sync_logs', [
            'support_ticket_id' => $ticketId,
            'sync_operation' => TicketSyncLog::OPERATION_TICKET_OUTBOUND,
            'direction' => 'outbound',
            'status' => 'success',
            'external_ticket_id' => '5678',
            'http_status_code' => 200,
        ]);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), '/auth_ticket/')
                && ($data['organization'] ?? null) === 3
                && ($data['partner'] ?? null) === 4
                && ($data['category'] ?? null) === 'Issue'
                && ($data['description'] ?? null) === '<p>Unable to login to the portal</p>'
                && $request->hasHeader('X-Api-Key', 'test-token');
        });
    }

    public function test_unauthorized_remote_response_keeps_crm_ticket_and_marks_sync_failed(): void
    {
        $this->enableIntegrationConfig();

        Http::fake([
            'https://partner.test/seva-api/v1/admin_settings/auth_ticket/' => Http::response([
                'detail' => 'Unauthorized',
            ], 401),
        ]);

        $employeeUser = CrmTestAccounts::employeeUser();
        $lookup = $this->createVerifiedLookup($employeeUser);

        $this->actingAs($employeeUser);

        $response = $this->postJson('/tickets', $this->createTicketPayload($lookup))
            ->assertCreated()
            ->assertJsonPath('data.sync_status', 'failed')
            ->assertJsonPath('data.external_ticket_id', null);

        $ticketId = (int) $response->json('data.id');

        $this->assertDatabaseHas('support_tickets', [
            'id' => $ticketId,
            'sync_status' => 'failed',
            'external_ticket_id' => null,
        ]);

        $this->assertDatabaseHas('ticket_sync_logs', [
            'support_ticket_id' => $ticketId,
            'sync_operation' => TicketSyncLog::OPERATION_TICKET_OUTBOUND,
            'status' => 'failed',
            'http_status_code' => 401,
        ]);
    }

    public function test_bad_request_remote_response_keeps_crm_ticket_and_marks_sync_failed(): void
    {
        $this->enableIntegrationConfig();

        Http::fake([
            'https://partner.test/seva-api/v1/admin_settings/auth_ticket/' => Http::response([
                'detail' => 'Invalid category',
            ], 400),
        ]);

        $employeeUser = CrmTestAccounts::employeeUser();
        $lookup = $this->createVerifiedLookup($employeeUser);

        $this->actingAs($employeeUser);

        $response = $this->postJson('/tickets', $this->createTicketPayload($lookup))
            ->assertCreated()
            ->assertJsonPath('data.sync_status', 'failed');

        $this->assertDatabaseHas('support_tickets', [
            'id' => (int) $response->json('data.id'),
            'sync_status' => 'failed',
        ]);
    }

    public function test_network_failure_keeps_crm_ticket_and_marks_sync_failed(): void
    {
        $this->enableIntegrationConfig();

        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $employeeUser = CrmTestAccounts::employeeUser();
        $lookup = $this->createVerifiedLookup($employeeUser);

        $this->actingAs($employeeUser);

        $response = $this->postJson('/tickets', $this->createTicketPayload($lookup))
            ->assertCreated()
            ->assertJsonPath('data.sync_status', 'failed');

        $this->assertDatabaseHas('support_tickets', [
            'id' => (int) $response->json('data.id'),
            'sync_status' => 'failed',
            'external_ticket_id' => null,
        ]);
    }

    public function test_retrying_outbound_sync_for_same_ticket_does_not_create_duplicate_remote_ticket(): void
    {
        $this->enableIntegrationConfig();

        Http::fake([
            'https://partner.test/seva-api/v1/admin_settings/auth_ticket/' => Http::response([
                'id' => 9012,
                'ticket_id' => 'TCK-9012',
            ], 200),
        ]);

        $employeeUser = CrmTestAccounts::employeeUser();
        $lookup = $this->createVerifiedLookup($employeeUser);

        $this->actingAs($employeeUser);

        $response = $this->postJson('/tickets', $this->createTicketPayload($lookup))->assertCreated();
        $ticket = SupportTicket::query()->findOrFail((int) $response->json('data.id'));

        app(CaCloudDeskOutboundTicketService::class)->pushAfterCrmCreate($ticket);
        app(CaCloudDeskOutboundTicketService::class)->pushAfterCrmCreate($ticket->fresh());

        Http::assertSentCount(1);

        $this->assertDatabaseHas('support_tickets', [
            'id' => $ticket->id,
            'external_ticket_id' => '9012',
            'sync_status' => 'synced',
        ]);
    }

    public function test_problem_type_maps_to_configured_ca_cloud_desk_category(): void
    {
        $service = app(CaCloudDeskOutboundTicketService::class);

        config([
            'ca_cloud_desk_integration.problem_type_category_map' => [
                'issue' => 'Issue',
                'improvement' => 'Improvement',
                'new_feature' => 'New Feature',
            ],
            'ca_cloud_desk_integration.default_category' => 'Issue',
        ]);

        $this->assertSame('Improvement', $service->mapProblemTypeToCategory('improvement'));
        $this->assertSame('New Feature', $service->mapProblemTypeToCategory('new_feature'));
        $this->assertSame('Issue', $service->mapProblemTypeToCategory('issue'));
    }

    public function test_extract_external_ticket_id_prefers_numeric_id(): void
    {
        $service = app(CaCloudDeskOutboundTicketService::class);

        $this->assertSame('5678', $service->extractExternalTicketId([
            'id' => 5678,
            'ticket_id' => 'TCK-5678',
        ]));

        $this->assertSame('TCK-ONLY', $service->extractExternalTicketId([
            'ticket_id' => 'TCK-ONLY',
        ]));
    }

    public function test_inbound_webhook_still_works_after_outbound_changes(): void
    {
        config([
            'ca_cloud_desk_integration.inbound_integration_token' => 'test-inbound-token-123',
        ]);

        $payload = [
            'id' => 4321,
            'organization' => 56,
            'organization_name' => 'Inbound Org',
            'description' => 'Inbound ticket body',
            'partner_data' => [
                'phone' => '9000000001',
                'email' => 'inbound@example.test',
            ],
            'category' => 'Reports',
        ];

        $this->postJson('/webhooks/ca-cloud-desk/tickets', $payload, [
            'X-Integration-Token' => 'test-inbound-token-123',
        ])
            ->assertCreated()
            ->assertJsonPath('data.external_ticket_id', '4321');

        $this->assertDatabaseHas('support_tickets', [
            'source_system' => SupportTicket::SOURCE_CA_CLOUD_DESK,
            'external_ticket_id' => '4321',
            'sync_status' => 'synced',
        ]);
    }
}
