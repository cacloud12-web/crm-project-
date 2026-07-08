<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\SmsTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CampaignScheduleValidationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_email_campaign_rejects_past_scheduled_at(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $lead = CaMaster::query()->whereNotNull('state_id')->firstOrFail();

        $this->postJson('/email-campaigns', [
            'campaign_name' => 'Past Schedule Email',
            'campaign_type' => 'Newsletter',
            'audience_mode' => 'selected_leads',
            'ca_ids' => [$lead->ca_id],
            'subject' => 'Hello',
            'body_template' => 'Test body',
            'scheduled_at' => now()->subHour()->toIso8601String(),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['scheduled_at']);
    }

    public function test_email_campaign_allows_blank_scheduled_at(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $lead = CaMaster::query()->whereNotNull('state_id')->firstOrFail();

        $this->postJson('/email-campaigns', [
            'campaign_name' => 'Immediate Email '.microtime(true),
            'campaign_type' => 'Newsletter',
            'audience_mode' => 'selected_leads',
            'ca_ids' => [$lead->ca_id],
            'subject' => 'Hello',
            'body_template' => 'Test body',
            'scheduled_at' => null,
        ])->assertCreated();
    }

    public function test_sms_campaign_rejects_past_scheduled_at(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $lead = CaMaster::query()->whereNotNull('state_id')->firstOrFail();
        $template = SmsTemplate::query()->first();
        if (! $template) {
            $template = SmsTemplate::query()->create([
                'template_name' => 'Schedule Test Template',
                'sender_id' => 'CACLOD',
                'dlt_template_id' => '1107161234567890123',
                'body_template' => 'Hello {#var#}',
                'variable_map' => ['ca_name'],
                'status' => SmsTemplate::STATUS_APPROVED,
                'is_active' => true,
            ]);
        }

        $this->postJson('/sms-campaigns', [
            'campaign_name' => 'Past Schedule SMS',
            'campaign_type' => 'Demo Reminder',
            'audience_mode' => 'selected_leads',
            'ca_ids' => [$lead->ca_id],
            'sms_template_id' => $template->id,
            'message_template' => $template->body_template,
            'scheduled_at' => now()->subMinutes(30)->toIso8601String(),
            'save_as_draft' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['scheduled_at']);
    }

    public function test_follow_up_rejects_past_scheduled_date_on_create(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $lead = CaMaster::query()->whereNotNull('state_id')->firstOrFail();

        $this->postJson('/follow-ups', [
            'ca_id' => $lead->ca_id,
            'followup_type' => 'Call',
            'remarks' => 'Past schedule test',
            'scheduled_date' => now()->subDay()->toIso8601String(),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['scheduled_date']);
    }
}
