<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WhatsAppSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return CrmTestAccounts::admin();
    }

    private function seedWhatsAppSettings(array $overrides = []): WhatsAppSetting
    {
        WhatsAppSetting::query()->delete();

        return WhatsAppSetting::query()->create(array_merge([
            'provider_name' => 'Meta WhatsApp Cloud API',
            'phone_number_id' => '1234567890',
            'business_account_id' => '9876543210',
            'access_token' => 'test-access-token',
            'api_version' => 'v23.0',
            'mode' => WhatsAppSetting::MODE_LIVE,
            'is_active' => true,
            'integration_status' => WhatsAppSetting::INTEGRATION_CONNECTED,
        ], $overrides));
    }

    public function test_settings_never_expose_access_token(): void
    {
        $this->seedWhatsAppSettings();
        $this->actingAs($this->admin());

        $response = $this->getJson('/whatsapp-settings');

        $response->assertOk()
            ->assertJsonPath('data.has_access_token', true)
            ->assertJsonStructure(['data' => ['integration_status', 'last_tested_at', 'phone_number_id']]);

        $this->assertArrayNotHasKey('access_token', $response->json('data'));
    }

    public function test_validate_configuration_does_not_call_meta_api(): void
    {
        $this->seedWhatsAppSettings();
        Http::fake();

        $this->actingAs($this->admin());

        $response = $this->postJson('/whatsapp-settings/validate');

        $response->assertOk()->assertJsonPath('data.valid', true);
        Http::assertNothingSent();
    }

    public function test_connection_test_verifies_credentials_without_sending_messages(): void
    {
        $this->seedWhatsAppSettings();
        Http::fake([
            'graph.facebook.com/*' => Http::sequence()
                ->push(['verified_name' => 'CA Cloud Desk', 'display_phone_number' => '+91 98765 43210'], 200)
                ->push(['name' => 'CA Cloud Desk WABA'], 200),
        ]);

        $this->actingAs($this->admin());

        $response = $this->postJson('/whatsapp-settings/test-connection');

        $response->assertOk()
            ->assertJsonPath('data.success', true)
            ->assertJsonPath('data.settings.integration_status', 'integrated');

        Http::assertSentCount(2);
    }

    public function test_connection_test_records_failure_for_invalid_token(): void
    {
        $this->seedWhatsAppSettings();
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Invalid OAuth access token', 'code' => 190],
            ], 401),
        ]);

        $this->actingAs($this->admin());

        $response = $this->postJson('/whatsapp-settings/test-connection');

        $response->assertOk()
            ->assertJsonPath('data.success', false)
            ->assertJsonPath('data.settings.integration_status', 'failed');
    }

    public function test_send_test_template_requires_live_integrated_mode(): void
    {
        $settings = $this->seedWhatsAppSettings([
            'mode' => WhatsAppSetting::MODE_SIMULATION,
            'integration_status' => WhatsAppSetting::INTEGRATION_CONNECTED,
            'test_mobile_number' => '9876543210',
        ]);

        $template = MessageTemplate::query()->updateOrCreate(
            [
                'channel' => 'whatsapp',
                'template_name' => 'integration_test_template',
                'language_code' => 'en',
            ],
            [
                'body_template' => 'Hello {{name}}',
                'status' => 'approved',
                'is_active' => true,
            ],
        );

        $this->actingAs($this->admin());

        $response = $this->postJson('/whatsapp-settings/send-test-template', [
            'message_template_id' => $template->id,
            'mobile_no' => '9876543210',
        ]);

        $response->assertStatus(422);
    }

    public function test_send_task_scheduled_reminder_template_logs_request_and_response(): void
    {
        $this->seedWhatsAppSettings([
            'integration_status' => WhatsAppSetting::INTEGRATION_INTEGRATED,
            'test_mobile_number' => '9876543210',
        ]);

        $template = MessageTemplate::query()->updateOrCreate(
            [
                'channel' => MessageTemplate::CHANNEL_WHATSAPP,
                'template_name' => 'task_customermp2et391nk',
                'language_code' => 'en_US',
            ],
            [
                'meta_api_name' => 'task_customermp2et391nk',
                'display_name' => 'Task Created Notification',
                'body_template' => 'Dear Mr. {{1}}, task "{{2}}" on {{3}}. Staff: {{4}}. Due: {{5}}.',
                'variable_map' => [
                    '{{1}}' => 'ca_name',
                    '{{2}}' => 'task_name',
                    '{{3}}' => 'task_date',
                    '{{4}}' => 'assigned_staff',
                    '{{5}}' => 'expected_completion',
                ],
                'meta_components' => [
                    'header' => [
                        'type' => 'document',
                        'document' => [
                            'link' => 'https://example.com/task.pdf',
                            'filename' => 'task-notification.pdf',
                        ],
                    ],
                ],
                'status' => MessageTemplate::STATUS_APPROVED,
                'is_active' => true,
            ],
        );

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '919876543210', 'wa_id' => '919876543210']],
                'messages' => [['id' => 'wamid.TASK123']],
            ], 200),
        ]);

        $this->actingAs($this->admin());

        $response = $this->postJson('/whatsapp-settings/send-test-template', [
            'message_template_id' => $template->id,
            'mobile_no' => '9876543210',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.success', true)
            ->assertJsonPath('data.meta_message_id', 'wamid.TASK123');

        $this->assertDatabaseHas('wa_message_logs', [
            'template_name' => 'task_customermp2et391nk',
            'message_status' => 'Sent',
            'meta_message_id' => 'wamid.TASK123',
        ]);

        Http::assertSent(function ($request) {
            $body = $request->data();
            $template = $body['template'] ?? [];
            $components = $template['components'] ?? [];

            return ($template['name'] ?? null) === 'task_customermp2et391nk'
                && ($body['messaging_product'] ?? null) === 'whatsapp'
                && ($body['to'] ?? null) === '919876543210'
                && ($template['language']['code'] ?? null) === 'en_US'
                && count($components) === 2
                && ($components[0]['type'] ?? null) === 'header'
                && ($components[1]['type'] ?? null) === 'body';
        });
    }

    public function test_send_test_template_returns_exact_meta_error(): void
    {
        $this->seedWhatsAppSettings([
            'integration_status' => WhatsAppSetting::INTEGRATION_INTEGRATED,
            'test_mobile_number' => '9876543210',
        ]);

        $template = MessageTemplate::query()->updateOrCreate(
            [
                'channel' => 'whatsapp',
                'template_name' => 'task_scheduled_reminder',
                'language_code' => 'en',
            ],
            [
                'body_template' => 'Hello {{name}}',
                'status' => 'approved',
                'is_active' => true,
            ],
        );

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Template name does not exist in the translation',
                    'code' => 132001,
                ],
            ], 404),
        ]);

        $this->actingAs($this->admin());

        $response = $this->postJson('/whatsapp-settings/send-test-template', [
            'message_template_id' => $template->id,
            'mobile_no' => '9876543210',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Template Not Found or Language Mismatch. Verify the template name and language (en_US) are approved in Meta WhatsApp Manager. Meta response: Template name does not exist in the translation');

        $this->assertDatabaseHas('wa_message_logs', [
            'template_name' => 'task_scheduled_reminder',
            'message_status' => 'Failed',
        ]);
    }

    public function test_webhook_verify_returns_challenge_when_token_matches(): void
    {
        $this->seedWhatsAppSettings([
            'webhook_verify_token' => 'my-verify-token',
        ]);

        $response = $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=my-verify-token&hub_challenge=12345');

        $response->assertOk()->assertSee('12345');
    }

    public function test_webhook_verify_rejected_without_configured_token(): void
    {
        $this->seedWhatsAppSettings(['webhook_verify_token' => null]);

        $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=anything&hub_challenge=12345')
            ->assertStatus(403);
    }

    public function test_webhook_message_template_status_update_marks_template_approved(): void
    {
        $template = MessageTemplate::query()->updateOrCreate(
            [
                'channel' => MessageTemplate::CHANNEL_WHATSAPP,
                'template_name' => 'task_customermp2et391nk',
                'language_code' => 'en_US',
            ],
            [
                'meta_api_name' => 'task_customermp2et391nk',
                'body_template' => 'Static task notification',
                'status' => MessageTemplate::STATUS_PENDING,
                'meta_status' => 'PENDING',
                'is_active' => false,
            ],
        );

        $this->postJson('/webhooks/whatsapp', [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '102290129340398',
                'time' => 1751247548,
                'changes' => [[
                    'field' => 'message_template_status_update',
                    'value' => [
                        'event' => 'APPROVED',
                        'message_template_id' => 1689556908129832,
                        'message_template_name' => 'task_customermp2et391nk',
                        'message_template_language' => 'en_US',
                        'reason' => 'NONE',
                        'message_template_category' => 'UTILITY',
                    ],
                ]],
            ]],
        ])->assertOk();

        $template->refresh();
        $this->assertSame('APPROVED', $template->meta_status);
        $this->assertSame(MessageTemplate::STATUS_APPROVED, $template->status);
        $this->assertTrue($template->is_active);
        $this->assertSame('1689556908129832', $template->meta_template_id);
    }

    public function test_submit_template_to_meta_calls_graph_api(): void
    {
        $this->seedWhatsAppSettings([
            'integration_status' => WhatsAppSetting::INTEGRATION_INTEGRATED,
        ]);

        $template = MessageTemplate::query()->updateOrCreate(
            [
                'channel' => MessageTemplate::CHANNEL_WHATSAPP,
                'template_name' => 'crm_new_task_alert',
                'language_code' => 'en',
            ],
            [
                'meta_api_name' => 'crm_new_task_alert',
                'body_template' => 'Dear {{name}}, task {{task_name}} is due on {{task_date}}.',
                'category' => 'UTILITY',
                'status' => MessageTemplate::STATUS_PENDING,
                'is_active' => true,
            ],
        );

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'id' => '999888777',
                'status' => 'PENDING',
                'category' => 'UTILITY',
            ], 200),
        ]);

        $this->actingAs($this->admin());

        $response = $this->postJson('/message-templates/whatsapp/'.$template->id.'/submit-meta');

        $response->assertOk()
            ->assertJsonPath('data.meta_template_id', '999888777')
            ->assertJsonPath('data.meta_status', 'PENDING');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/message_templates')
                && ($body['name'] ?? null) === 'crm_new_task_alert'
                && ($body['language'] ?? null) === 'en'
                && isset($body['components'][0]['text']);
        });
    }
}
