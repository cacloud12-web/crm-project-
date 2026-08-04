<?php

namespace Tests\Unit;

use App\Exceptions\Ticket\CaCloudDeskIntegrationException;
use App\Services\Ticket\Integration\CaCloudDeskHttpClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CaCloudDeskHttpClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ca_cloud_desk_integration.enabled' => true,
            'ca_cloud_desk_integration.base_url' => 'https://lawseva.test',
            'ca_cloud_desk_integration.api_token' => 'secret-key',
            'ca_cloud_desk_integration.api_key_header' => 'X-Api-Key',
            'ca_cloud_desk_integration.organizations_endpoint' => '/seva-api/v1/admin_settings/auth_organizations/',
            'ca_cloud_desk_integration.employee_endpoint' => '/seva-api/v1/admin_settings/auth_employee/',
            'ca_cloud_desk_integration.ticket_endpoint' => '/seva-api/v1/admin_settings/auth_ticket/',
            'ca_cloud_desk_integration.retry_times' => 0,
        ]);
    }

    public function test_list_organizations_maps_id_and_name_and_sends_api_key(): void
    {
        Http::fake([
            'https://lawseva.test/seva-api/v1/admin_settings/auth_organizations/' => Http::response([
                ['id' => 56, 'name' => 'vikash ltd'],
                ['id' => 3, 'name' => 'Demo Organization'],
            ], 200),
        ]);

        $client = new CaCloudDeskHttpClient;
        $orgs = $client->lookupOrganizations('9876543210');

        $this->assertSame([
            ['organization_number' => '56', 'organization_name' => 'vikash ltd'],
            ['organization_number' => '3', 'organization_name' => 'Demo Organization'],
        ], $orgs);

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Api-Key', 'secret-key')
                && ! $request->hasHeader('Authorization')
                && $request->url() === 'https://lawseva.test/seva-api/v1/admin_settings/auth_organizations/';
        });
    }

    public function test_verify_organization_uses_auth_employee_and_maps_fields(): void
    {
        Http::fake([
            'https://lawseva.test/seva-api/v1/admin_settings/auth_employee/*' => Http::response([
                'employee' => 460,
                'name' => 'devansh.agrawal',
                'email' => 'devansh.agrawal@plutonic.co.in',
                'phone' => '8273337174',
                'organization' => 56,
                'organization_name' => 'vikash ltd',
                'organization_email' => 'jyothis.benny@plutonic.co.in',
            ], 200),
        ]);

        $client = new CaCloudDeskHttpClient;
        $verified = $client->verifyOrganization('8273337174', '56');

        $this->assertSame('56', $verified['organization_number']);
        $this->assertSame('vikash ltd', $verified['organization_name']);
        $this->assertSame('jyothis.benny@plutonic.co.in', $verified['email']);
        $this->assertSame(460, $verified['partner_id']);

        Http::assertSent(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && ($query['organization'] ?? null) === '56'
                && ($query['username'] ?? null) === '8273337174'
                && $request->hasHeader('X-Api-Key', 'secret-key');
        });
    }

    public function test_create_auth_ticket_posts_body_with_partner(): void
    {
        Http::fake([
            'https://lawseva.test/seva-api/v1/admin_settings/auth_ticket/' => Http::response([
                'id' => 1234,
                'ticket_id' => 'TCK-1234',
                'organization' => 56,
                'partner' => 460,
            ], 200),
        ]);

        $client = new CaCloudDeskHttpClient;
        $ticket = $client->createAuthTicket([
            'organization' => 56,
            'partner' => 460,
            'category' => 'Add Document Template',
            'description' => '<p>Need help</p>',
            'documents' => [],
        ]);

        $this->assertSame('TCK-1234', $ticket['ticket_id']);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->method() === 'POST'
                && ($data['organization'] ?? null) === 56
                && ($data['partner'] ?? null) === 460
                && ($data['category'] ?? null) === 'Add Document Template'
                && $request->hasHeader('X-Api-Key', 'secret-key');
        });
    }

    public function test_unauthorized_maps_to_exception(): void
    {
        Http::fake([
            'https://lawseva.test/seva-api/v1/admin_settings/auth_organizations/' => Http::response([
                'detail' => 'Unauthorized',
            ], 401),
        ]);

        $this->expectException(CaCloudDeskIntegrationException::class);
        $this->expectExceptionMessage('Unauthorized');

        (new CaCloudDeskHttpClient)->listOrganizations();
    }
}
