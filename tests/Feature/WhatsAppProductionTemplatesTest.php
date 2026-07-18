<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Models\CaMaster;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Services\WhatsApp\MetaWhatsAppErrorMapper;
use App\Services\WhatsApp\WhatsAppCloudMappingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppProductionTemplatesTest extends TestCase
{
    use DatabaseTransactions;

    private function syncProductionWhatsAppTemplates(): void
    {
        MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->whereNotIn('template_name', [
                'expense_partnerjeyfg90rzl',
                'proforma_invoicel5ekuo0baa',
            ])
            ->update([
                'is_active' => false,
                'publish_status' => 'disabled',
                'status' => 'archived',
            ]);

        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_07_11_200000_sync_production_whatsapp_templates.php',
        ]);
    }

    private function admin(): User
    {
        return CrmTestAccounts::admin();
    }

    public function test_only_production_templates_are_active_after_migration(): void
    {
        $this->syncProductionWhatsAppTemplates();

        $activeNames = MessageTemplate::query()
            ->where('channel', MessageTemplate::CHANNEL_WHATSAPP)
            ->where('is_active', true)
            ->pluck('template_name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'expense_partnerjeyfg90rzl',
            'proforma_invoicel5ekuo0baa',
        ], $activeNames);
    }

    public function test_expense_template_variables_are_mapped(): void
    {
        $this->syncProductionWhatsAppTemplates();

        $template = MessageTemplate::query()
            ->where('template_name', 'expense_partnerjeyfg90rzl')
            ->firstOrFail();

        $lead = CaMaster::query()->firstOrFail();
        $lead->update(['ca_name' => 'Test Client']);

        $service = app(WhatsAppCloudMappingService::class);
        $variables = $service->resolveTemplateVariables($template, $lead, [
            'AMOUNT' => '5,000',
            'EXPENSE_DATE' => '11-July-2026',
            'EXPENSE_CATEGORY' => 'Office Supplies',
            'EXPENSE_ID' => 'EXP-99',
        ]);

        $this->assertSame('Test Client', $variables['{{CLIENT_NAME}}']);
        $this->assertSame('5,000', $variables['{{AMOUNT}}']);
        $this->assertSame('11-July-2026', $variables['{{EXPENSE_DATE}}']);
        $this->assertSame('Office Supplies', $variables['{{EXPENSE_CATEGORY}}']);
        $this->assertSame('EXP-99', $variables['{{EXPENSE_ID}}']);

        $body = $service->renderTemplateBody($template->body_template, $variables);
        $this->assertStringContainsString('Test Client', $body);
        $this->assertStringContainsString('5,000', $body);
        $this->assertStringContainsString('EXP-99', $body);
    }

    public function test_proforma_template_builds_document_payload(): void
    {
        $this->syncProductionWhatsAppTemplates();

        $template = MessageTemplate::query()
            ->where('template_name', 'proforma_invoicel5ekuo0baa')
            ->firstOrFail();

        $lead = CaMaster::query()->firstOrFail();
        $service = app(WhatsAppCloudMappingService::class);
        $payload = $service->buildCloudPayload($lead, $template);

        $this->assertSame('proforma_invoicel5ekuo0baa', $payload['request_body']['template']['name']);
        $this->assertSame('en_US', $payload['request_body']['template']['language']['code']);
        $this->assertNotEmpty($payload['request_body']['template']['components'] ?? []);
    }

    public function test_meta_error_mapper_returns_specific_messages(): void
    {
        $this->assertStringContainsString(
            'Template Not Found',
            MetaWhatsAppErrorMapper::map(['error' => ['code' => 132001, 'message' => 'Template not found']], 400),
        );

        $this->assertStringContainsString(
            'Invalid Access Token',
            MetaWhatsAppErrorMapper::map(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token']], 401),
        );

        $this->assertStringContainsString(
            'Rate Limit Exceeded',
            MetaWhatsAppErrorMapper::map(['error' => ['code' => 4, 'message' => 'Application request limit reached']], 429),
        );
    }

    public function test_send_test_expense_template_logs_payload_and_response(): void
    {
        WhatsAppSetting::query()->delete();
        WhatsAppSetting::query()->create([
            'provider_name' => 'Meta WhatsApp Cloud API',
            'phone_number_id' => '1234567890',
            'business_account_id' => '9876543210',
            'access_token' => 'test-access-token',
            'api_version' => 'v23.0',
            'mode' => WhatsAppSetting::MODE_LIVE,
            'is_active' => true,
            'integration_status' => WhatsAppSetting::INTEGRATION_INTEGRATED,
            'test_mobile_number' => '9876543210',
        ]);

        $this->syncProductionWhatsAppTemplates();

        $template = MessageTemplate::query()
            ->where('template_name', 'expense_partnerjeyfg90rzl')
            ->firstOrFail();

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.test-expense-001']],
            ], 200),
        ]);

        $this->actingAs($this->admin());

        $response = $this->postJson('/whatsapp-settings/send-test-template', [
            'message_template_id' => $template->id,
            'mobile_no' => '9876543210',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.meta_message_id', 'wamid.test-expense-001');

        $this->assertDatabaseHas('wa_message_logs', [
            'template_name' => 'expense_partnerjeyfg90rzl',
            'meta_message_id' => 'wamid.test-expense-001',
            'message_status' => 'Sent',
        ]);
    }

    public function test_whatsapp_settings_include_connection_dashboard_fields(): void
    {
        WhatsAppSetting::query()->delete();
        WhatsAppSetting::query()->create([
            'provider_name' => 'Meta WhatsApp Cloud API',
            'phone_number_id' => '1234567890',
            'business_account_id' => '9876543210',
            'access_token' => 'test-access-token',
            'webhook_verify_token' => 'verify-token',
            'api_version' => 'v23.0',
            'mode' => WhatsAppSetting::MODE_LIVE,
            'is_active' => true,
            'integration_status' => WhatsAppSetting::INTEGRATION_INTEGRATED,
        ]);

        $this->syncProductionWhatsAppTemplates();

        $this->actingAs($this->admin());

        $response = $this->getJson('/whatsapp-settings');

        $response->assertOk()
            ->assertJsonPath('data.connection_status', 'Connected')
            ->assertJsonPath('data.connection_connected', true)
            ->assertJsonPath('data.webhook_status', 'configured')
            ->assertJsonPath('data.api_status', 'configured')
            ->assertJsonPath('data.approved_templates_count', 2)
            ->assertJsonStructure(['data' => ['callback_url', 'last_sync_at', 'token_status']]);
    }
}
