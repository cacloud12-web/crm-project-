<?php

namespace Tests\Unit;

use App\Models\CaMaster;
use App\Models\LeadAssignmentEngine;
use App\Models\MessageTemplate;
use App\Services\WhatsApp\WhatsAppCloudMappingService;
use App\Services\WhatsApp\WhatsAppSettingsService;
use App\Services\WhatsApp\WhatsAppTemplateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class WhatsAppCloudMappingServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_template_variables_are_mapped_from_lead_fields(): void
    {
        $lead = CaMaster::query()->whereNotNull('mobile_no')->firstOrFail();

        $service = app(WhatsAppCloudMappingService::class);
        $variables = $service->resolveVariables($lead);

        $this->assertArrayHasKey('{{name}}', $variables);
        $this->assertArrayHasKey('{{firm_name}}', $variables);
        $this->assertArrayHasKey('{{mobile}}', $variables);
        $this->assertSame((string) $lead->ca_name, $variables['{{name}}']);
    }

    public function test_cloud_payload_structure_is_generated_without_http(): void
    {
        $lead = CaMaster::query()->whereNotNull('mobile_no')->firstOrFail();

        $template = MessageTemplate::query()->updateOrCreate(
            [
                'channel' => 'whatsapp',
                'template_name' => 'unit_test_template',
                'language_code' => 'en',
            ],
            [
                'body_template' => 'Hello {{name}} from {{firm_name}}',
                'status' => 'approved',
                'is_active' => true,
            ],
        );

        $service = app(WhatsAppCloudMappingService::class);
        $payload = $service->buildCloudPayload($lead, $template);

        $this->assertSame('whatsapp_cloud_v1', $payload['mapping_version']);
        $this->assertSame('template', $payload['request_body']['type']);
        $this->assertSame('unit_test_template', $payload['request_body']['template']['name']);
        $this->assertSame('en', $payload['request_body']['template']['language']['code']);
        $this->assertStringContainsString('graph.facebook.com', $payload['endpoint']);
        $this->assertIsBool($payload['auth']['access_token_configured']);
        $this->assertArrayHasKey('components', $payload['request_body']['template']);
    }

    public function test_recipient_mobile_is_normalized_with_country_code(): void
    {
        $service = app(WhatsAppCloudMappingService::class);

        $this->assertSame('919876543210', $service->normalizeRecipientMobile('9876543210'));
    }

    public function test_company_registration_docs_meta_payload_matches_numbered_variables(): void
    {
        $lead = CaMaster::query()->whereNotNull('mobile_no')->firstOrFail();
        $lead->ca_name = 'Test Client';

        $template = MessageTemplate::query()->updateOrCreate(
            [
                'channel' => MessageTemplate::CHANNEL_WHATSAPP,
                'template_name' => 'company_registration_docs',
                'language_code' => 'en',
            ],
            [
                'body_template' => 'Hi {{1}}, please send the required docs for company registration. Need help? Reach us. -{{2}} lawseva',
                'category' => 'UTILITY',
                'status' => MessageTemplate::STATUS_APPROVED,
                'variable_map' => [
                    '{{1}}' => 'ca_name',
                    '{{2}}' => 'static:LawSeva',
                ],
                'is_active' => true,
            ],
        );

        $service = app(WhatsAppCloudMappingService::class);
        $payload = $service->buildCloudPayload($lead, $template);

        $this->assertSame('company_registration_docs', $payload['request_body']['template']['name']);
        $this->assertSame('en', $payload['request_body']['template']['language']['code']);
        $this->assertSame(
            ['Test Client', 'LawSeva'],
            $payload['crm_mapping']['body_parameters'],
        );
        $this->assertSame(
            'Test Client',
            $payload['request_body']['template']['components'][0]['parameters'][0]['text'],
        );
        $this->assertSame(
            'LawSeva',
            $payload['request_body']['template']['components'][0]['parameters'][1]['text'],
        );
    }

    public function test_sanitize_meta_body_parameters_replaces_empty_assigned_staff(): void
    {
        $template = MessageTemplate::query()->updateOrCreate(
            [
                'channel' => MessageTemplate::CHANNEL_WHATSAPP,
                'template_name' => 'task_customermp2et391nk',
                'language_code' => 'en_US',
            ],
            [
                'body_template' => 'Staff: {{4}}',
                'variable_map' => ['{{4}}' => 'assigned_staff'],
                'status' => MessageTemplate::STATUS_APPROVED,
                'is_active' => true,
            ],
        );

        $service = app(WhatsAppCloudMappingService::class);
        $sanitized = $service->sanitizeMetaBodyParameters([''], $template);

        $this->assertSame(['Not assigned'], $sanitized);
    }

    public function test_task_template_payload_uses_not_assigned_when_lead_has_no_assignment(): void
    {
        $lead = CaMaster::query()->whereNotNull('mobile_no')->firstOrFail();
        LeadAssignmentEngine::query()->where('ca_id', $lead->ca_id)->delete();

        $template = MessageTemplate::query()->where('template_name', 'task_customermp2et391nk')->first();
        if (! $template) {
            $this->markTestSkipped('task_customermp2et391nk template not seeded.');
        }

        $service = app(WhatsAppCloudMappingService::class);
        $payload = $service->buildCloudPayload($lead, $template);
        $bodyParams = $payload['request_body']['template']['components'][1]['parameters'] ?? [];

        $this->assertCount(5, $bodyParams);
        $this->assertNotSame('', $bodyParams[3]['text'] ?? '');
        $this->assertSame('Not assigned', $bodyParams[3]['text'] ?? null);
    }
}
