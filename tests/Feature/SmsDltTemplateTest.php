<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Models\CaMaster;
use App\Models\Employee;
use App\Models\LeadAssignmentEngine;
use App\Models\SmsCampaign;
use App\Models\SmsLog;
use App\Models\SmsLogStatus;
use App\Models\SmsSetting;
use App\Models\SmsTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsDltTemplateTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return CrmTestAccounts::admin();
    }

    private function employee(): User
    {
        return CrmTestAccounts::employeeUser();
    }

    private function assignLeadToEmployee(CaMaster $lead): void
    {
        $employee = CrmTestAccounts::employee();
        LeadAssignmentEngine::query()->updateOrCreate(
            ['ca_id' => $lead->ca_id, 'status' => 'Active'],
            ['employee_id' => $employee->employee_id, 'assigned_date' => now()->toDateString()],
        );
    }

    private function updateLeadMobile(CaMaster $lead, string $mobile = '9876543210'): void
    {
        $lead->update([
            'ca_name' => 'Example CA',
            'firm_name' => 'Example Firm',
            'mobile_no' => $mobile,
            'normalized_mobile' => $mobile,
        ]);
    }

    private function seedIntegratedSettings(): void
    {
        SmsSetting::query()->delete();
        SmsSetting::query()->create([
            'provider_name' => 'SMS Alert',
            'api_url' => SmsSetting::DEFAULT_API_URL,
            'api_key' => 'test-api-key',
            'sender_id' => 'CACLOD',
            'dlt_template_id' => '1107161234567890123',
            'mode' => SmsSetting::MODE_LIVE,
            'is_active' => true,
            'integration_status' => SmsSetting::INTEGRATION_INTEGRATED,
            'last_test_status' => 'success',
        ]);
    }

    private function approvedTemplate(): SmsTemplate
    {
        return SmsTemplate::query()->create([
            'template_name' => 'Test DLT Template',
            'sender_id' => 'CACLOD',
            'dlt_template_id' => '1107161234567890123',
            'body_template' => 'Hello {#var#}, welcome to {#var#}.',
            'variable_map' => ['ca_name', 'firm_name'],
            'status' => SmsTemplate::STATUS_APPROVED,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_create_sms_template(): void
    {
        $this->actingAs($this->admin());

        $response = $this->postJson('/sms-templates', [
            'template_name' => 'Payment Reminder',
            'sender_id' => 'CACLOD',
            'dlt_template_id' => '1107169876543210987',
            'body_template' => 'Dear {#var#}, payment due for {#var#}.',
            'status' => SmsTemplate::STATUS_APPROVED,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('sms_templates', [
            'template_name' => 'Payment Reminder',
            'sender_id' => 'CACLOD',
            'dlt_template_id' => '1107169876543210987',
            'status' => SmsTemplate::STATUS_APPROVED,
        ]);
    }

    public function test_dlt_template_preview_replaces_variables(): void
    {
        $template = $this->approvedTemplate();
        $lead = CaMaster::query()->firstOrFail();
        $this->updateLeadMobile($lead);

        $this->actingAs($this->employee());

        $response = $this->postJson('/sms-templates/preview', [
            'sms_template_id' => $template->id,
            'lead_id' => $lead->ca_id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.preview', 'Hello Example CA, welcome to Example Firm.');
        $response->assertJsonPath('data.dlt_template_id', '1107161234567890123');
    }

    public function test_campaign_process_sends_sms_and_logs_sent_status(): void
    {
        $this->seedIntegratedSettings();
        $template = $this->approvedTemplate();
        $lead = CaMaster::query()->firstOrFail();
        $this->updateLeadMobile($lead);
        $this->assignLeadToEmployee($lead);

        Http::fake([
            SmsSetting::DEFAULT_API_URL => Http::response([
                'status' => 'success',
                'message_id' => 'MSG999',
            ], 200),
        ]);

        $campaign = SmsCampaign::query()->create([
            'campaign_name' => 'DLT Test Campaign',
            'campaign_type' => 'Demo Reminder',
            'audience_mode' => 'selected_leads',
            'audience_label' => 'Selected Leads (1 leads)',
            'selected_ca_ids' => [$lead->ca_id],
            'sender_id' => 'CACLOD',
            'sms_template_id' => $template->id,
            'message_template' => $template->body_template,
            'status' => 'Draft',
            'performed_by' => 'Admin',
            'total_sms' => 1,
        ]);

        $this->actingAs($this->employee());

        $response = $this->postJson('/sms-campaigns/'.$campaign->id.'/process');

        $response->assertOk();
        $response->assertJsonPath('data.status', 'Completed');
        $response->assertJsonPath('data.delivered_count', 1);

        $this->assertDatabaseHas('sms_logs', [
            'campaign_id' => $campaign->id,
            'sms_template_id' => $template->id,
            'template_name' => $template->template_name,
            'dlt_template_id' => '1107161234567890123',
            'sms_status' => SmsLogStatus::SENT,
            'message' => 'Hello Example CA, welcome to Example Firm.',
        ]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['sender'] ?? null) === 'CACLOD'
                && ($body['mobileno'] ?? null) === '9876543210'
                && ($body['text'] ?? null) === 'Hello Example CA, welcome to Example Firm.'
                && ($body['templateid'] ?? null) === '1107161234567890123';
        });
    }

    public function test_campaign_process_logs_api_error_on_provider_failure(): void
    {
        $this->seedIntegratedSettings();
        $template = $this->approvedTemplate();
        $lead = CaMaster::query()->firstOrFail();
        $this->updateLeadMobile($lead);

        Http::fake([
            SmsSetting::DEFAULT_API_URL => Http::response([
                'status' => 'error',
                'description' => 'DLT template mismatch',
            ], 200),
        ]);

        $campaign = SmsCampaign::query()->create([
            'campaign_name' => 'DLT Failure Campaign',
            'campaign_type' => 'Demo Reminder',
            'audience_mode' => 'selected_leads',
            'audience_label' => 'Selected Leads (1 leads)',
            'selected_ca_ids' => [$lead->ca_id],
            'sender_id' => 'CACLOD',
            'sms_template_id' => $template->id,
            'message_template' => $template->body_template,
            'status' => 'Draft',
            'performed_by' => 'Admin',
            'total_sms' => 1,
        ]);

        $this->actingAs($this->admin());

        $response = $this->postJson('/sms-campaigns/'.$campaign->id.'/process');

        $response->assertOk();
        $response->assertJsonPath('data.status', 'Failed');
        $response->assertJsonPath('data.failed_count', 1);

        $log = SmsLog::query()->where('campaign_id', $campaign->id)->first();
        $this->assertNotNull($log);
        $this->assertSame(SmsLogStatus::API_ERROR, $log->sms_status);
        $this->assertSame('DLT template mismatch', $log->error_message);
    }

    public function test_employee_can_list_approved_templates(): void
    {
        $this->approvedTemplate();

        $this->actingAs($this->employee());

        $response = $this->getJson('/sms-templates?approved_only=1');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('template_name')->all();
        $this->assertContains('Test DLT Template', $names);
    }

    public function test_campaign_process_works_when_integration_test_failed_but_config_valid(): void
    {
        $this->seedIntegratedSettings();
        SmsSetting::query()->first()?->update([
            'integration_status' => SmsSetting::INTEGRATION_FAILED,
            'last_test_status' => 'failed',
            'last_test_response' => json_encode(['description' => 'Invalid Template Match']),
        ]);

        $template = $this->approvedTemplate();
        $lead = CaMaster::query()->firstOrFail();
        $this->updateLeadMobile($lead);
        $this->assignLeadToEmployee($lead);

        Http::fake([
            SmsSetting::DEFAULT_API_URL => Http::response([
                'status' => 'success',
                'message_id' => 'MSG888',
            ], 200),
        ]);

        $campaign = SmsCampaign::query()->create([
            'campaign_name' => 'Failed Integration Send Test',
            'campaign_type' => 'Demo Reminder',
            'audience_mode' => 'selected_leads',
            'audience_label' => 'Selected Leads (1 leads)',
            'selected_ca_ids' => [$lead->ca_id],
            'sender_id' => 'CACLOD',
            'sms_template_id' => $template->id,
            'message_template' => $template->body_template,
            'status' => 'Draft',
            'performed_by' => 'Admin',
            'total_sms' => 1,
        ]);

        $this->actingAs($this->employee());

        $response = $this->postJson('/sms-campaigns/'.$campaign->id.'/process');

        $response->assertOk();
        $response->assertJsonPath('data.status', 'Completed');
    }

    public function test_sms_campaign_with_future_schedule_is_saved_as_scheduled(): void
    {
        $this->seedIntegratedSettings();
        $template = $this->approvedTemplate();
        $lead = CaMaster::query()->firstOrFail();
        $this->updateLeadMobile($lead);

        $this->actingAs($this->admin());

        $response = $this->postJson('/sms-campaigns', [
            'campaign_name' => 'Scheduled SMS Campaign',
            'campaign_type' => 'Demo Reminder',
            'audience_mode' => 'selected_leads',
            'ca_ids' => [$lead->ca_id],
            'sms_template_id' => $template->id,
            'message_template' => $template->body_template,
            'scheduled_at' => now('Asia/Kolkata')->addDay()->format('Y-m-d\TH:i:sP'),
            'save_as_draft' => true,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'Scheduled');
        $this->assertDatabaseHas('sms_campaigns', [
            'campaign_name' => 'Scheduled SMS Campaign',
            'status' => 'Scheduled',
        ]);
    }

    public function test_live_mode_settings_require_dlt_template_id(): void
    {
        SmsSetting::query()->delete();
        SmsSetting::query()->create([
            'provider_name' => 'SMS Alert',
            'api_url' => SmsSetting::DEFAULT_API_URL,
            'api_key' => 'test-api-key',
            'sender_id' => 'CACLOD',
            'dlt_template_id' => null,
            'mode' => SmsSetting::MODE_LIVE,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin());

        $response = $this->postJson('/sms-settings/validate');

        $response->assertOk();
        $response->assertJsonPath('data.valid', false);
        $this->assertContains(
            'DLT Template ID is required when SMS provider is in Live mode.',
            $response->json('data.errors'),
        );
    }
}
