<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\City;
use App\Models\DemoSchedule;
use App\Models\FollowUp;
use App\Models\State;
use App\Models\User;
use App\Support\Demo\DemoProviderResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FollowUpDemoFieldsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_demo_provider_resolver_tiers(): void
    {
        $this->assertSame('Ankit Bhardwaj', DemoProviderResolver::resolve(1)['provider']);
        $this->assertSame('https://meet.google.com/mcq-jrnh-uea', DemoProviderResolver::resolve(1)['meeting_link']);

        $mid = DemoProviderResolver::resolve(5);
        $this->assertSame('Dev Aggarwal', $mid['provider']);
        $this->assertSame('https://meet.google.com/awm-gsft-xov', $mid['meeting_link']);

        $large = DemoProviderResolver::resolve(15);
        $this->assertSame('Kamal Sharma', $large['provider']);
        $this->assertSame('https://meet.google.com/ouq-sxne-jwn', $large['meeting_link']);
    }

    public function test_demo_scheduled_follow_up_stores_team_provider_and_link_from_lead_team_size(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $lead = $this->createLeadWithTeamSize(8);
        $scheduled = now()->addDays(3)->setTime(15, 30);

        $response = $this->postJson('/follow-ups', [
            'ca_id' => $lead->ca_id,
            'followup_type' => 'Demo Scheduled',
            'scheduled_date' => $scheduled->toDateTimeString(),
            'status' => 'Pending',
            'remarks' => 'Demo booking',
            'meeting_link' => 'https://meet.google.com/awm-gsft-xov',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.team_size', 8)
            ->assertJsonPath('data.demo_provider_name', 'Dev Aggarwal')
            ->assertJsonPath('data.meeting_link', 'https://meet.google.com/awm-gsft-xov');

        $this->deleteJson('/ca-masters/'.$lead->ca_id)->assertOk();
    }

    public function test_changing_team_size_recalculates_provider_and_link(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $lead = $this->createLeadWithTeamSize(1);
        $scheduled = now()->addDays(2)->setTime(11, 0);

        $create = $this->postJson('/follow-ups', [
            'ca_id' => $lead->ca_id,
            'followup_type' => 'Demo Scheduled',
            'scheduled_date' => $scheduled->toDateTimeString(),
            'team_size' => 1,
            'meeting_link' => 'https://meet.google.com/mcq-jrnh-uea',
        ])->assertCreated();

        $followupId = $create->json('data.followup_id');

        $this->putJson('/follow-ups/'.$followupId, [
            'team_size' => 12,
            'meeting_link' => 'https://meet.google.com/ouq-sxne-jwn',
        ])->assertOk()
            ->assertJsonPath('data.team_size', 12)
            ->assertJsonPath('data.demo_provider_name', 'Kamal Sharma')
            ->assertJsonPath('data.meeting_link', 'https://meet.google.com/ouq-sxne-jwn');

        $this->deleteJson('/ca-masters/'.$lead->ca_id)->assertOk();
    }

    public function test_demo_scheduled_requires_meeting_link_when_team_size_unavailable(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $lead = $this->createLeadWithTeamSize(null);
        $scheduled = now()->addDays(2)->setTime(11, 0);

        $this->postJson('/follow-ups', [
            'ca_id' => $lead->ca_id,
            'followup_type' => 'Demo Scheduled',
            'scheduled_date' => $scheduled->toDateTimeString(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['meeting_link']);

        $this->deleteJson('/ca-masters/'.$lead->ca_id)->assertOk();
    }

    public function test_demo_scheduled_succeeds_when_sms_api_key_cannot_be_decrypted(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $settings = \App\Models\SmsSetting::query()->first();
        if ($settings) {
            $settings->forceFill([
                'api_key' => 'invalid-ciphertext',
                'mode' => \App\Models\SmsSetting::MODE_SIMULATION,
            ])->save();
        }

        $lead = $this->createLeadWithTeamSize(8);
        $scheduled = now()->addDays(3)->setTime(15, 30);

        $this->postJson('/follow-ups', [
            'ca_id' => $lead->ca_id,
            'followup_type' => 'Demo Scheduled',
            'scheduled_date' => $scheduled->toDateTimeString(),
            'meeting_link' => 'https://meet.google.com/awm-gsft-xov',
        ])->assertCreated()
            ->assertJsonPath('data.followup_type', 'Demo Scheduled');

        $this->deleteJson('/ca-masters/'.$lead->ca_id)->assertOk();
    }

    public function test_non_demo_follow_up_does_not_require_meeting_link(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $lead = $this->createLeadWithTeamSize(3);
        $scheduled = now()->addDays(2)->setTime(11, 0);

        $this->postJson('/follow-ups', [
            'ca_id' => $lead->ca_id,
            'followup_type' => 'Call Status',
            'scheduled_date' => $scheduled->toDateTimeString(),
            'remarks' => 'Call back',
        ])->assertCreated()
            ->assertJsonPath('data.meeting_link', null);

        $this->deleteJson('/ca-masters/'.$lead->ca_id)->assertOk();
    }

    public function test_demo_scheduled_can_be_marked_completed_using_existing_meeting_link(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $lead = $this->createLeadWithTeamSize(8);
        $scheduled = now()->addDays(3)->setTime(15, 30);

        $create = $this->postJson('/follow-ups', [
            'ca_id' => $lead->ca_id,
            'followup_type' => 'Demo Scheduled',
            'scheduled_date' => $scheduled->toDateTimeString(),
            'meeting_link' => 'https://meet.google.com/awm-gsft-xov',
        ])->assertCreated();

        $followupId = $create->json('data.followup_id');

        $this->patchJson('/follow-ups/'.$followupId, [
            'status' => 'Completed',
        ])->assertOk()
            ->assertJsonPath('data.status', 'Completed');

        $this->deleteJson('/ca-masters/'.$lead->ca_id)->assertOk();
    }

    public function test_demo_scheduled_can_be_marked_completed_when_link_exists_on_demo_schedule_only(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $lead = $this->createLeadWithTeamSize(8);
        $scheduled = now()->addDays(2)->setTime(11, 0);

        $followUp = FollowUp::query()->create([
            'ca_id' => $lead->ca_id,
            'followup_type' => 'Demo Scheduled',
            'scheduled_date' => $scheduled,
            'status' => 'Pending',
            'meeting_link' => null,
        ]);

        DemoSchedule::query()->create([
            'ca_id' => $lead->ca_id,
            'followup_id' => $followUp->followup_id,
            'demo_at' => $scheduled,
            'meeting_link' => 'https://meet.google.com/demo-schedule-only',
            'status' => 'scheduled',
        ]);

        $this->patchJson('/follow-ups/'.$followUp->followup_id, [
            'status' => 'Completed',
        ])->assertOk()
            ->assertJsonPath('data.status', 'Completed');

        $this->deleteJson('/ca-masters/'.$lead->ca_id)->assertOk();
    }

    private function createLeadWithTeamSize(?int $teamSize): CaMaster
    {
        $ts = (string) microtime(true);
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $mobile = '9'.substr(str_replace('.', '', $ts), -9);

        $payload = [
            'firm_name' => 'Demo Fields Firm '.$ts,
            'ca_name' => 'Demo Fields CA',
            'mobile_no' => $mobile,
            'email_id' => "demo.fields.{$ts}@test.local",
            'state_id' => $state->state_id,
            'city_id' => $city->city_id,
            'status' => 'Hot',
        ];

        if ($teamSize !== null) {
            $payload['team_size'] = $teamSize;
        }

        $response = $this->postJson('/ca-masters', $payload)->assertCreated();

        return CaMaster::query()->findOrFail($response->json('data.ca_id'));
    }
}
