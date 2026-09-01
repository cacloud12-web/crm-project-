<?php

namespace Tests\Unit;

use App\Models\MessageTemplate;
use App\Models\WhatsAppSetting;
use App\Services\WhatsApp\WhatsAppCloudMappingService;
use App\Services\WhatsApp\WhatsAppMetaTemplateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppFlowButtonTemplateTest extends TestCase
{
    use DatabaseTransactions;

    private function createWhatsAppTemplate(array $overrides = []): MessageTemplate
    {
        return MessageTemplate::query()->create(array_merge([
            'channel' => MessageTemplate::CHANNEL_WHATSAPP,
            'template_name' => 'flow_button_test_'.uniqid(),
            'language_code' => 'en',
            'body_template' => 'Hello {{name}}',
            'status' => MessageTemplate::STATUS_APPROVED,
            'is_active' => true,
            'meta_components' => [
                'parameter_format' => 'named',
                'body_parameters' => ['name'],
            ],
        ], $overrides));
    }

    /**
     * @param  list<array<string, mixed>>  $buttons
     * @return array<string, mixed>
     */
    private function metaStatusPayloadWithButtons(array $buttons, string $bodyText = 'Hello {{name}}'): array
    {
        return [
            'name' => 'flow_button_test',
            'status' => 'APPROVED',
            'language' => 'en',
            'components' => [
                ['type' => 'BODY', 'text' => $bodyText],
                ['type' => 'BUTTONS', 'buttons' => $buttons],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function expectedFlowButtonComponent(string $index): array
    {
        return [
            'type' => 'button',
            'sub_type' => 'flow',
            'index' => $index,
            'parameters' => [
                [
                    'type' => 'action',
                    'action' => [
                        'flow_token' => 'unused',
                    ],
                ],
            ],
        ];
    }

    public function test_body_only_normal_template_has_no_button_components(): void
    {
        $template = $this->createWhatsAppTemplate([
            'meta_status_payload' => [
                'name' => 'body_only',
                'status' => 'APPROVED',
                'components' => [
                    ['type' => 'BODY', 'text' => 'Hello {{name}}'],
                ],
            ],
        ]);

        $service = app(WhatsAppCloudMappingService::class);
        $components = $service->buildMetaTemplateComponents($template, ['Test User']);

        $this->assertCount(1, $components);
        $this->assertSame('body', $components[0]['type'] ?? null);
        $this->assertEmpty(array_filter($components, fn ($c) => ($c['type'] ?? '') === 'button'));
    }

    public function test_static_url_and_phone_template_has_no_button_components(): void
    {
        $template = $this->createWhatsAppTemplate([
            'meta_status_payload' => $this->metaStatusPayloadWithButtons([
                ['type' => 'URL', 'text' => 'Visit', 'url' => 'https://example.com'],
                ['type' => 'PHONE_NUMBER', 'text' => 'Call', 'phone_number' => '+919876543210'],
            ]),
        ]);

        $service = app(WhatsAppCloudMappingService::class);
        $components = $service->buildMetaTemplateComponents($template, ['Test User']);

        $this->assertCount(1, $components);
        $this->assertSame('body', $components[0]['type'] ?? null);
        $this->assertEmpty(array_filter($components, fn ($c) => ($c['type'] ?? '') === 'button'));
    }

    public function test_flow_button_at_index_0(): void
    {
        $template = $this->createWhatsAppTemplate([
            'meta_status_payload' => $this->metaStatusPayloadWithButtons([
                ['type' => 'FLOW', 'text' => 'Start Flow', 'flow_id' => '111222333'],
            ]),
        ]);

        $service = app(WhatsAppCloudMappingService::class);
        $components = $service->buildMetaTemplateComponents($template, ['Test User']);

        $this->assertSame($this->expectedFlowButtonComponent('0'), $components[1]);
    }

    public function test_flow_button_at_index_1(): void
    {
        $template = $this->createWhatsAppTemplate([
            'meta_status_payload' => $this->metaStatusPayloadWithButtons([
                ['type' => 'URL', 'text' => 'Visit', 'url' => 'https://example.com'],
                ['type' => 'FLOW', 'text' => 'Start Flow', 'flow_id' => '111222333'],
            ]),
        ]);

        $service = app(WhatsAppCloudMappingService::class);
        $components = $service->buildMetaTemplateComponents($template, ['Test User']);

        $this->assertSame($this->expectedFlowButtonComponent('1'), $components[1]);
    }

    public function test_flow_button_at_index_2(): void
    {
        $template = $this->createWhatsAppTemplate([
            'meta_status_payload' => $this->metaStatusPayloadWithButtons([
                ['type' => 'URL', 'text' => 'Visit', 'url' => 'https://example.com'],
                ['type' => 'PHONE_NUMBER', 'text' => 'Call', 'phone_number' => '+919876543210'],
                ['type' => 'FLOW', 'text' => 'Renewal Options', 'flow_id' => '111222333'],
            ]),
        ]);

        $service = app(WhatsAppCloudMappingService::class);
        $components = $service->buildMetaTemplateComponents($template, ['Test User']);

        $this->assertSame($this->expectedFlowButtonComponent('2'), $components[1]);
    }

    public function test_body_variables_and_flow_button(): void
    {
        $template = $this->createWhatsAppTemplate([
            'body_template' => 'Hello {{name}}, renew on {{renewal_date}}',
            'meta_components' => [
                'parameter_format' => 'named',
                'body_parameters' => ['name', 'renewal_date'],
            ],
            'meta_status_payload' => $this->metaStatusPayloadWithButtons(
                [['type' => 'FLOW', 'text' => 'Renew', 'flow_id' => '111222333']],
                'Hello {{name}}, renew on {{renewal_date}}',
            ),
        ]);

        $service = app(WhatsAppCloudMappingService::class);
        $components = $service->buildMetaTemplateComponents($template, ['CA Ravi Kumar', '15-Sep-2026']);

        $this->assertSame('body', $components[0]['type'] ?? null);
        $this->assertCount(2, $components[0]['parameters'] ?? []);
        $this->assertSame($this->expectedFlowButtonComponent('0'), $components[1]);
    }

    public function test_dynamic_url_button_behavior_when_enabled(): void
    {
        config(['whatsapp_cloud.enable_template_button_parameters' => true]);

        $template = $this->createWhatsAppTemplate([
            'meta_status_payload' => $this->metaStatusPayloadWithButtons([
                ['type' => 'URL', 'text' => 'Join', 'url' => 'https://meet.google.com/{{link}}'],
            ]),
            'meta_components' => [
                'parameter_format' => 'named',
                'body_parameters' => ['name'],
                'buttons' => [[
                    'index' => '0',
                    'sub_type' => 'url',
                    'parameter_source' => 'link',
                    'url_base' => 'https://meet.google.com/',
                    'requires_send_parameter' => true,
                ]],
            ],
        ]);

        $service = app(WhatsAppCloudMappingService::class);
        $components = $service->buildMetaTemplateComponents($template, ['Test User'], [
            '{{link}}' => 'abc-defg-hij',
        ]);

        $urlButton = collect($components)->firstWhere('sub_type', 'url');
        $this->assertNotNull($urlButton);
        $this->assertSame('button', $urlButton['type'] ?? null);
        $this->assertSame('0', $urlButton['index'] ?? null);
        $this->assertSame('text', $urlButton['parameters'][0]['type'] ?? null);
        $this->assertEmpty(collect($components)->firstWhere('sub_type', 'flow'));
    }

    public function test_parse_meta_template_structure_detects_flow_button_index_from_buttons_array(): void
    {
        $service = app(WhatsAppMetaTemplateService::class);
        $parsed = $service->parseMetaTemplateStructure($this->metaStatusPayloadWithButtons([
            ['type' => 'URL', 'text' => 'Visit', 'url' => 'https://example.com'],
            ['type' => 'PHONE_NUMBER', 'text' => 'Call', 'phone_number' => '+919876543210'],
            ['type' => 'FLOW', 'text' => 'Renewal Options', 'flow_id' => '999888777'],
        ]));

        $this->assertSame([
            [
                'index' => '2',
                'flow_id' => '999888777',
                'requires_send_parameter' => true,
                'sub_type' => 'flow',
            ],
        ], $parsed['flow_buttons'] ?? null);
        $this->assertArrayNotHasKey('buttons', $parsed);
    }

    public function test_flow_validation_checks_published_status_and_waba(): void
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
        ]);

        $template = $this->createWhatsAppTemplate([
            'meta_components' => [
                'parameter_format' => 'named',
                'body_parameters' => ['name'],
                'flow_buttons' => [[
                    'index' => '0',
                    'flow_id' => '555666777',
                    'requires_send_parameter' => true,
                    'sub_type' => 'flow',
                ]],
            ],
        ]);

        Http::fake([
            'graph.facebook.com/*/555666777*' => Http::response([
                'id' => '555666777',
                'status' => 'DRAFT',
                'whatsapp_business_account' => ['id' => '9876543210'],
            ], 200),
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(WhatsAppMetaTemplateService::class)->assertFlowButtonsReadyForMetaSend($template);
    }

    public function test_subscription_renewal_reminder_includes_body_and_flow_button_components(): void
    {
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_09_01_160000_refresh_subscription_renewal_reminder_meta_template.php',
        ]);
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_09_01_190000_store_subscription_renewal_reminder_meta_status_payload.php',
        ]);

        $template = MessageTemplate::query()
            ->where('template_name', 'subscription_renewal_reminder')
            ->firstOrFail();

        $service = app(WhatsAppCloudMappingService::class);
        $components = $service->buildMetaTemplateComponents(
            $template,
            ['CA Ravi Kumar', '15-Sep-2026', 'Professional Plan', '15,000'],
        );

        $this->assertCount(2, $components);
        $this->assertSame('body', $components[0]['type'] ?? null);
        $this->assertCount(4, $components[0]['parameters'] ?? []);
        $this->assertSame($this->expectedFlowButtonComponent('2'), $components[1]);
    }
}
