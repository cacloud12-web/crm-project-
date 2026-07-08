<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\City;
use App\Models\DemoConfirmation;
use App\Models\FollowUp;
use App\Models\SmsSetting;
use App\Models\State;
use App\Models\User;
use App\Services\DemoConfirmation\DemoConfirmationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DemoConfirmationTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsAdmin(): User
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        return $admin;
    }

    private function configureSmsSettings(): void
    {
        $settings = SmsSetting::query()->first();
        if (! $settings) {
            $settings = SmsSetting::query()->create([
                'provider_name' => 'SMS Alert',
                'api_url' => SmsSetting::DEFAULT_API_URL,
                'mode' => SmsSetting::MODE_SIMULATION,
            ]);
        }

        $settings->update([
            'api_key' => 'test-key',
            'sender_id' => 'TESTID',
            'mode' => SmsSetting::MODE_SIMULATION,
            'is_active' => true,
        ]);
    }

    public function test_demo_scheduled_creates_confirmation_and_mapped_sms_log(): void
    {
        $admin = $this->actingAsAdmin();
        $this->configureSmsSettings();

        $ts = (string) microtime(true);
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $mobile = '9'.substr(str_replace('.', '', $ts), -9);

        $leadResponse = $this->postJson('/ca-masters', [
            'firm_name' => 'Demo Confirm Firm '.$ts,
            'ca_name' => 'Demo Confirm CA',
            'mobile_no' => $mobile,
            'email_id' => "demo.confirm.{$ts}@test.local",
            'state_id' => $state->state_id,
            'city_id' => $city->city_id,
            'status' => 'Demo Scheduled',
            'team_size' => 6,
        ])->assertCreated();

        $leadId = $leadResponse->json('data.ca_id');
        $scheduled = now()->addDays(2)->setTime(11, 0);

        $response = $this->postJson('/follow-ups', [
            'ca_id' => $leadId,
            'followup_type' => 'Demo Scheduled',
            'scheduled_date' => $scheduled->toDateTimeString(),
            'status' => 'Pending',
            'remarks' => 'Initial demo booking',
        ]);

        $response->assertCreated();
        $followupId = $response->json('data.followup_id');

        $confirmation = DemoConfirmation::query()
            ->where('followup_id', $followupId)
            ->latest('id')
            ->first();

        $this->assertNotNull($confirmation);
        $this->assertSame(DemoConfirmation::STATUS_PENDING, $confirmation->confirmation_status);
        $this->assertNotNull($confirmation->sms_log_id);
        $this->assertNotNull($confirmation->last_sms_sent_at);

        $this->assertDatabaseHas('activity_logs', [
            'module_name' => 'DEMO_CONFIRMATION',
            'action' => 'Demo Scheduled',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'module_name' => 'DEMO_CONFIRMATION',
            'action' => 'Confirmation SMS Sent',
        ]);

        $this->deleteJson('/ca-masters/'.$leadId)->assertOk();
    }

    public function test_demo_reschedule_creates_new_confirmation_and_reschedule_log(): void
    {
        $this->actingAsAdmin();
        $this->configureSmsSettings();

        $ts = (string) microtime(true);
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $mobile = '8'.substr(str_replace('.', '', $ts), -9);

        $leadResponse = $this->postJson('/ca-masters', [
            'firm_name' => 'Reschedule Firm '.$ts,
            'ca_name' => 'Reschedule CA',
            'mobile_no' => $mobile,
            'email_id' => "reschedule.{$ts}@test.local",
            'state_id' => $state->state_id,
            'city_id' => $city->city_id,
            'status' => 'Demo Scheduled',
            'team_size' => 6,
        ])->assertCreated();

        $leadId = $leadResponse->json('data.ca_id');
        $original = now()->addDays(2)->setTime(11, 0);
        $rescheduled = now()->addDays(3)->setTime(15, 0);

        $create = $this->postJson('/follow-ups', [
            'ca_id' => $leadId,
            'followup_type' => 'Demo Scheduled',
            'scheduled_date' => $original->toDateTimeString(),
            'status' => 'Pending',
        ])->assertCreated();

        $followupId = $create->json('data.followup_id');

        $this->putJson('/follow-ups/'.$followupId, [
            'ca_id' => $leadId,
            'followup_type' => 'Demo Scheduled',
            'scheduled_date' => $rescheduled->toDateTimeString(),
            'status' => 'Pending',
            'remarks' => 'Customer requested new slot',
        ])->assertOk();

        $this->assertDatabaseHas('demo_reschedule_logs', [
            'followup_id' => $followupId,
            'lead_id' => $leadId,
        ]);

        $latest = DemoConfirmation::query()
            ->where('followup_id', $followupId)
            ->orderByDesc('id')
            ->first();

        $this->assertTrue($latest->is_reschedule);
        $this->assertSame(DemoConfirmation::STATUS_PENDING, $latest->confirmation_status);

        $this->assertDatabaseHas('activity_logs', [
            'module_name' => 'DEMO_CONFIRMATION',
            'action' => 'Demo Rescheduled',
        ]);

        $this->deleteJson('/ca-masters/'.$leadId)->assertOk();
    }

    public function test_customer_yes_reply_confirms_demo(): void
    {
        $this->actingAsAdmin();

        $lead = CaMaster::query()->firstOrFail();
        $lead->update(['mobile_no' => '9876543210']);

        $followUp = FollowUp::query()->create([
            'ca_id' => $lead->ca_id,
            'followup_type' => 'Demo Scheduled',
            'scheduled_date' => now()->addDay()->setTime(11, 0),
            'status' => 'Pending',
        ]);

        app(DemoConfirmationService::class)
            ->handleFollowUpCreated($followUp);

        $confirmation = DemoConfirmation::query()->where('followup_id', $followUp->followup_id)->firstOrFail();

        $this->postJson('/demo-confirmations/inbound-reply', [
            'demo_confirmation_id' => $confirmation->id,
            'reply' => 'YES',
        ])->assertOk()
            ->assertJsonPath('data.confirmation_status', DemoConfirmation::STATUS_CONFIRMED);

        $this->assertDatabaseHas('activity_logs', [
            'module_name' => 'DEMO_CONFIRMATION',
            'action' => 'Customer Confirmed',
        ]);
    }

    public function test_customer_no_reply_rejects_demo_and_notifies(): void
    {
        $this->actingAsAdmin();

        $lead = CaMaster::query()->firstOrFail();
        $lead->update(['mobile_no' => '9876543210']);

        $followUp = FollowUp::query()->create([
            'ca_id' => $lead->ca_id,
            'followup_type' => 'Demo Scheduled',
            'scheduled_date' => now()->addDay()->setTime(11, 0),
            'status' => 'Pending',
        ]);

        app(DemoConfirmationService::class)
            ->handleFollowUpCreated($followUp);

        $confirmation = DemoConfirmation::query()->where('followup_id', $followUp->followup_id)->firstOrFail();

        $this->postJson('/demo-confirmations/inbound-reply', [
            'demo_confirmation_id' => $confirmation->id,
            'reply' => 'NO',
        ])->assertOk()
            ->assertJsonPath('data.confirmation_status', DemoConfirmation::STATUS_REJECTED);

        $this->assertDatabaseHas('crm_notifications', [
            'type' => 'demo_rejected',
        ]);
    }

    public function test_lead_demo_confirmation_summary_endpoint(): void
    {
        $this->actingAsAdmin();
        $lead = CaMaster::query()->firstOrFail();

        $response = $this->getJson('/ca-masters/'.$lead->ca_id.'/demo-confirmation');
        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => ['summary', 'history', 'timeline'],
            ]);
    }

    public function test_dashboard_includes_demo_confirmation_metrics(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/dashboard/metrics');
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'demo_confirmations' => [
                        'demo_confirmation_pending',
                        'demo_confirmation_confirmed',
                        'demo_confirmation_rejected',
                        'demo_confirmation_rescheduled',
                        'demo_confirmation_rejected_after_reschedule',
                    ],
                ],
            ]);
    }
}
