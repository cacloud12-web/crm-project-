<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\ConsentTracking;
use App\Models\DndManagement;
use App\Models\Employee;
use App\Models\LeadAssignmentEngine;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WaMessageLog;
use App\Models\WaMessageLogStatus;
use App\Models\WhatsAppSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppCampaignTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::query()->where('email', 'admin@ca.local')->firstOrFail();
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
            'integration_status' => WhatsAppSetting::INTEGRATION_INTEGRATED,
        ], $overrides));
    }

    private function approvedTemplate(): MessageTemplate
    {
        return MessageTemplate::query()->updateOrCreate(
            [
                'channel' => MessageTemplate::CHANNEL_WHATSAPP,
                'template_name' => 'demo_confirmation',
                'language_code' => 'en',
            ],
            [
                'meta_api_name' => 'demo_confirmation',
                'display_name' => 'Demo Confirmation',
                'body_template' => 'Hello {{name}}, this is for {{firm_name}} in {{city}}. Mobile: {{mobile}}.',
                'status' => MessageTemplate::STATUS_APPROVED,
                'is_active' => true,
            ],
        );
    }

    private function prepareLeadForWhatsApp(CaMaster $lead): void
    {
        $this->assignLead($lead);
        $this->grantWhatsAppConsent($lead);
        DndManagement::query()
            ->where('ca_id', $lead->ca_id)
            ->orWhere('mobile_no', $lead->mobile_no)
            ->delete();
    }

    private function grantWhatsAppConsent(CaMaster $lead): void
    {
        ConsentTracking::query()->updateOrCreate(
            ['ca_id' => $lead->ca_id, 'consent_type' => 'WhatsApp'],
            ['consent_status' => 'Yes', 'consent_date' => now()->toDateString()],
        );
    }

    private function assignLead(CaMaster $lead): void
    {
        $employee = Employee::query()->firstOrFail();
        LeadAssignmentEngine::query()->updateOrCreate(
            ['ca_id' => $lead->ca_id, 'status' => 'Active'],
            ['employee_id' => $employee->employee_id, 'assigned_date' => now()->toDateString()],
        );
    }

    public function test_preview_message_replaces_template_variables(): void
    {
        $this->actingAs($this->admin());
        $template = $this->approvedTemplate();
        $lead = CaMaster::query()->whereNotNull('mobile_no')->firstOrFail();
        $lead->load(['city', 'state']);

        $response = $this->postJson('/whatsapp-campaigns/preview-message', [
            'message_template_id' => $template->id,
            'lead_id' => $lead->ca_id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.lead_id', $lead->ca_id)
            ->assertJsonStructure(['data' => ['preview', 'variables', 'api_payload']]);

        $preview = (string) $response->json('data.preview');
        $this->assertStringContainsString((string) $lead->ca_name, $preview);
        $this->assertStringContainsString((string) $lead->firm_name, $preview);
        $this->assertStringNotContainsString('{{name}}', $preview);
    }

    public function test_campaign_send_creates_pending_logs_and_dispatches_to_meta(): void
    {
        $this->seedWhatsAppSettings();
        $template = $this->approvedTemplate();
        $admin = $this->admin();
        $this->actingAs($admin);

        $lead = CaMaster::query()->whereNotNull('mobile_no')->firstOrFail();
        $this->prepareLeadForWhatsApp($lead);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.CAMPAIGN123']],
            ], 200),
        ]);

        $response = $this->postJson('/whatsapp-campaigns', [
            'campaign_name' => 'WA Campaign Test',
            'campaign_type' => 'Demo Confirmation',
            'audience_mode' => 'selected_leads',
            'ca_ids' => [$lead->ca_id],
            'message_template_id' => $template->id,
            'message_template' => $template->body_template,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'Completed');

        $campaignId = (int) $response->json('data.id');

        $this->assertDatabaseHas('whatsapp_campaigns', [
            'id' => $campaignId,
            'campaign_name' => 'WA Campaign Test',
        ]);

        $this->assertDatabaseHas('wa_message_logs', [
            'campaign_id' => $campaignId,
            'ca_id' => $lead->ca_id,
            'message_status' => WaMessageLogStatus::SENT,
            'meta_message_id' => 'wamid.CAMPAIGN123',
        ]);

        Http::assertSentCount(1);
    }

    public function test_campaign_validation_requires_template_and_audience(): void
    {
        $this->seedWhatsAppSettings();
        $this->actingAs($this->admin());

        $response = $this->postJson('/whatsapp-campaigns/validate', [
            'audience_mode' => 'selected_leads',
            'ca_ids' => [],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.valid', false);
    }

    public function test_webhook_updates_delivered_status_and_campaign_counts(): void
    {
        $this->actingAs($this->admin());
        $log = WaMessageLog::query()->create([
            'campaign_id' => null,
            'ca_id' => null,
            'mobile_no' => '9876543210',
            'template_name' => 'demo_confirmation',
            'language_code' => 'en',
            'message' => 'Hello',
            'message_status' => WaMessageLogStatus::SENT,
            'meta_message_id' => 'wamid.WEBHOOK123',
            'queued_at' => now(),
            'sent_at' => now(),
        ]);

        $campaignResponse = $this->postJson('/whatsapp-campaigns', [
            'campaign_name' => 'Webhook Campaign',
            'campaign_type' => 'Demo Confirmation',
            'audience_mode' => 'all_leads',
            'message_template' => 'Hello {{name}}',
        ]);

        $campaignId = (int) $campaignResponse->json('data.id');
        $log->update(['campaign_id' => $campaignId]);

        $this->postJson('/webhooks/whatsapp', [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'statuses' => [[
                            'id' => 'wamid.WEBHOOK123',
                            'status' => 'delivered',
                        ]],
                    ],
                ]],
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('wa_message_logs', [
            'id' => $log->id,
            'message_status' => WaMessageLogStatus::DELIVERED,
        ]);
    }

    public function test_campaign_send_without_executive_assignment_dispatches_to_meta(): void
    {
        $this->seedWhatsAppSettings();
        $template = $this->approvedTemplate();
        $admin = $this->admin();
        $this->actingAs($admin);

        $lead = CaMaster::query()->whereNotNull('mobile_no')->firstOrFail();
        $this->grantWhatsAppConsent($lead);
        DndManagement::query()
            ->where('ca_id', $lead->ca_id)
            ->orWhere('mobile_no', $lead->mobile_no)
            ->delete();
        LeadAssignmentEngine::query()->where('ca_id', $lead->ca_id)->delete();

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.UNASSIGNED123']],
            ], 200),
        ]);

        $response = $this->postJson('/whatsapp-campaigns', [
            'campaign_name' => 'WA Unassigned Lead Campaign',
            'campaign_type' => 'Demo Confirmation',
            'audience_mode' => 'selected_leads',
            'ca_ids' => [$lead->ca_id],
            'message_template_id' => $template->id,
            'message_template' => $template->body_template,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'Completed');

        $this->assertDatabaseHas('wa_message_logs', [
            'campaign_id' => $response->json('data.id'),
            'ca_id' => $lead->ca_id,
            'message_status' => WaMessageLogStatus::SENT,
            'meta_message_id' => 'wamid.UNASSIGNED123',
        ]);

        Http::assertSentCount(1);
    }

    public function test_campaign_send_without_consent_record_dispatches_to_meta(): void
    {
        $this->seedWhatsAppSettings();
        $template = MessageTemplate::query()->where('template_name', 'task_customermp2et391nk')->first()
            ?? $this->approvedTemplate();
        if (! $template->meta_api_name) {
            $template->update(['meta_api_name' => 'task_customermp2et391nk']);
        }
        $admin = $this->admin();
        $this->actingAs($admin);

        $lead = CaMaster::query()->whereNotNull('mobile_no')->firstOrFail();
        ConsentTracking::query()->where('ca_id', $lead->ca_id)->where('consent_type', 'WhatsApp')->delete();
        DndManagement::query()
            ->where('ca_id', $lead->ca_id)
            ->orWhere('mobile_no', $lead->mobile_no)
            ->delete();

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.NOCONSENT123']],
            ], 200),
        ]);

        $response = $this->postJson('/whatsapp-campaigns', [
            'campaign_name' => 'WA No Consent Campaign',
            'campaign_type' => 'Demo Confirmation',
            'audience_mode' => 'selected_leads',
            'ca_ids' => [$lead->ca_id],
            'message_template_id' => $template->id,
            'message_template' => $template->body_template,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'Completed');

        $this->assertDatabaseHas('wa_message_logs', [
            'campaign_id' => $response->json('data.id'),
            'ca_id' => $lead->ca_id,
            'message_status' => WaMessageLogStatus::SENT,
            'meta_message_id' => 'wamid.NOCONSENT123',
        ]);

        Http::assertSentCount(1);
    }

    public function test_task_customermp2et391nk_template_sends_with_meta_components(): void
    {
        $this->seedWhatsAppSettings();
        $admin = $this->admin();
        $this->actingAs($admin);

        $template = MessageTemplate::query()->updateOrCreate(
            [
                'channel' => MessageTemplate::CHANNEL_WHATSAPP,
                'template_name' => 'task_customermp2et391nk',
                'language_code' => 'en',
            ],
            [
                'meta_api_name' => 'task_customermp2et391nk',
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

        $lead = CaMaster::query()->whereNotNull('mobile_no')->firstOrFail();
        $this->prepareLeadForWhatsApp($lead);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.TASKMETA123']],
            ], 200),
        ]);

        $response = $this->postJson('/whatsapp-campaigns', [
            'campaign_name' => 'Task Template Campaign',
            'campaign_type' => 'Demo Confirmation',
            'audience_mode' => 'selected_leads',
            'ca_ids' => [$lead->ca_id],
            'message_template_id' => $template->id,
            'message_template' => $template->body_template,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'Completed');

        $this->assertDatabaseHas('wa_message_logs', [
            'campaign_id' => $response->json('data.id'),
            'message_status' => WaMessageLogStatus::SENT,
            'meta_message_id' => 'wamid.TASKMETA123',
        ]);

        Http::assertSent(function ($request) {
            $body = $request->data();
            $template = $body['template'] ?? [];
            $components = $template['components'] ?? [];

            return ($template['name'] ?? null) === 'task_customermp2et391nk'
                && ($template['language']['code'] ?? null) === 'en_US'
                && count($components) === 2
                && ($components[0]['type'] ?? null) === 'header'
                && ($components[0]['parameters'][0]['type'] ?? null) === 'document'
                && ($components[1]['type'] ?? null) === 'body'
                && count($components[1]['parameters'] ?? []) === 5;
        });
    }

    public function test_campaign_marks_failed_when_all_recipients_skipped(): void
    {
        $this->seedWhatsAppSettings();
        $template = $this->approvedTemplate();
        $this->actingAs($this->admin());

        $lead = CaMaster::query()->whereNotNull('mobile_no')->firstOrFail();
        DndManagement::query()->updateOrCreate(
            ['ca_id' => $lead->ca_id, 'mobile_no' => $lead->mobile_no, 'dnd_type' => 'WA'],
            ['email_id' => $lead->email_id],
        );

        $response = $this->postJson('/whatsapp-campaigns', [
            'campaign_name' => 'WA DND Campaign',
            'campaign_type' => 'Demo Confirmation',
            'audience_mode' => 'selected_leads',
            'ca_ids' => [$lead->ca_id],
            'message_template_id' => $template->id,
            'message_template' => $template->body_template,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'Failed');

        Http::assertNothingSent();
    }

    public function test_failed_meta_response_marks_log_as_failed(): void
    {
        $this->seedWhatsAppSettings();
        $template = $this->approvedTemplate();
        $admin = $this->admin();
        $this->actingAs($admin);

        $lead = CaMaster::query()->whereNotNull('mobile_no')->firstOrFail();
        $this->prepareLeadForWhatsApp($lead);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Template not found', 'code' => 132001],
            ], 400),
        ]);

        $response = $this->postJson('/whatsapp-campaigns', [
            'campaign_name' => 'WA Failed Campaign',
            'campaign_type' => 'Demo Confirmation',
            'audience_mode' => 'selected_leads',
            'ca_ids' => [$lead->ca_id],
            'message_template_id' => $template->id,
            'message_template' => $template->body_template,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'Failed');

        $this->assertDatabaseHas('wa_message_logs', [
            'campaign_id' => $response->json('data.id'),
            'message_status' => WaMessageLogStatus::FAILED,
        ]);
    }
}
