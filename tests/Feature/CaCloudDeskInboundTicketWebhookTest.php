<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CaCloudDeskInboundTicketWebhookTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ca_cloud_desk_integration.inbound_integration_token' => 'test-inbound-token-123',
        ]);
    }

    public function test_inbound_webhook_requires_token(): void
    {
        $this->postJson('/webhooks/ca-cloud-desk/tickets', [
            'id' => 1234,
            'organization' => 56,
            'organization_name' => 'Demo Org',
            'description' => 'Need help',
        ])->assertUnauthorized();
    }

    public function test_inbound_webhook_creates_ticket_from_lawseva_payload(): void
    {
        $payload = [
            'id' => 1234,
            'ticket_id' => 'TCK-1234',
            'organization' => 56,
            'organization_name' => 'vikash ltd',
            'partner' => 460,
            'partner_data' => [
                'id' => 460,
                'first_name' => 'devansh.agrawal',
                'email' => 'devansh.agrawal@plutonic.co.in',
                'phone' => '8273337174',
            ],
            'category' => 'Add Document Template',
            'description' => '<p>Need help with document template</p>',
            'documents' => [],
            'is_solved' => false,
            'created_at' => '2026-07-23T05:38:00.000000Z',
        ];

        $response = $this->postJson('/webhooks/ca-cloud-desk/tickets', $payload, [
            'X-Integration-Token' => 'test-inbound-token-123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.created', true)
            ->assertJsonPath('data.external_ticket_id', '1234');

        $this->assertDatabaseHas('support_tickets', [
            'source_system' => SupportTicket::SOURCE_CA_CLOUD_DESK,
            'external_ticket_id' => '1234',
            'organization_number' => '56',
            'organization_name' => 'vikash ltd',
            'mobile_number' => '8273337174',
            'email' => 'devansh.agrawal@plutonic.co.in',
            'problem_type' => 'issue',
            'status' => 'open',
        ]);
    }

    public function test_inbound_webhook_is_idempotent(): void
    {
        $payload = [
            'external_ticket_id' => '9999',
            'organization_number' => '3',
            'organization_name' => 'Demo Organization',
            'customer_name' => 'Demo Organization',
            'raised_by_name' => 'Partner',
            'mobile_number' => '9000000001',
            'description' => 'First sync',
            'problem_type' => 'issue',
        ];

        $this->postJson('/webhooks/ca-cloud-desk/tickets', $payload, [
            'X-Api-Key' => 'test-inbound-token-123',
        ])->assertCreated();

        $payload['description'] = 'Updated description';
        $second = $this->postJson('/webhooks/ca-cloud-desk/tickets', $payload, [
            'X-Api-Key' => 'test-inbound-token-123',
        ]);

        $second->assertOk()->assertJsonPath('data.created', false);

        $this->assertSame(1, SupportTicket::query()
            ->where('source_system', SupportTicket::SOURCE_CA_CLOUD_DESK)
            ->where('external_ticket_id', '9999')
            ->count());

        $this->assertDatabaseHas('support_tickets', [
            'external_ticket_id' => '9999',
            'description' => 'Updated description',
        ]);
    }
}
