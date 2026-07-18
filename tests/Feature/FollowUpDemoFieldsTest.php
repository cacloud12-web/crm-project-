<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Models\CaMaster;
use App\Models\City;
use App\Models\DemoProvider;
use App\Models\DemoSchedule;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\State;
use App\Models\User;
use App\Support\Demo\DemoProviderResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FollowUpDemoFieldsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_demo_provider_resolver_uses_database_tiers(): void
    {
        $tiers = $this->seedDemoProviders();

        $this->assertSame($tiers[0]['name'], DemoProviderResolver::resolve(1)['provider']);
        $this->assertSame($tiers[0]['link'], DemoProviderResolver::resolve(1)['meeting_link']);

        $mid = DemoProviderResolver::resolve(5);
        $this->assertSame($tiers[1]['name'], $mid['provider']);
        $this->assertSame($tiers[1]['link'], $mid['meeting_link']);

        $large = DemoProviderResolver::resolve(15);
        $this->assertSame($tiers[2]['name'], $large['provider']);
        $this->assertSame($tiers[2]['link'], $large['meeting_link']);
    }

    public function test_demo_scheduled_follow_up_stores_team_provider_and_link_from_lead_team_size(): void
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        $provider = $this->createEligibleProvider('Fixture Mid', 1, 10, 'https://meet.example.com/fixture-mid');
        $lead = $this->createLeadWithTeamSize(8);
        $scheduled = now()->addDays(3)->setTime(15, 30);

        $response = $this->postJson('/follow-ups', [
            'ca_id' => $lead->ca_id,
            'followup_type' => 'Demo Scheduled',
            'scheduled_date' => $scheduled->toDateTimeString(),
            'status' => 'Pending',
            'remarks' => 'Demo booking',
            'demo_provider_employee_id' => $provider->employee_id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.team_size', 8)
            ->assertJsonPath('data.demo_provider_name', 'Fixture Mid')
            ->assertJsonPath('data.demo_provider_employee_id', $provider->employee_id)
            ->assertJsonPath('data.meeting_link', 'https://meet.example.com/fixture-mid');

        $this->deleteJson('/ca-masters/'.$lead->ca_id)->assertOk();
    }

    public function test_changing_team_size_recalculates_provider_and_link(): void
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        $small = $this->createEligibleProvider('Fixture Small', 1, 1, 'https://meet.example.com/fixture-small');
        $large = $this->createEligibleProvider('Fixture Large', 11, 50, 'https://meet.example.com/fixture-large');
        $lead = $this->createLeadWithTeamSize(1);
        $scheduled = now()->addDays(2)->setTime(11, 0);

        $create = $this->postJson('/follow-ups', [
            'ca_id' => $lead->ca_id,
            'followup_type' => 'Demo Scheduled',
            'scheduled_date' => $scheduled->toDateTimeString(),
            'team_size' => 1,
            'demo_provider_employee_id' => $small->employee_id,
        ])->assertCreated();

        $followupId = $create->json('data.followup_id');

        $this->putJson('/follow-ups/'.$followupId, [
            'team_size' => 12,
            'demo_provider_employee_id' => $large->employee_id,
        ])->assertOk()
            ->assertJsonPath('data.team_size', 12)
            ->assertJsonPath('data.demo_provider_name', 'Fixture Large')
            ->assertJsonPath('data.demo_provider_employee_id', $large->employee_id)
            ->assertJsonPath('data.meeting_link', 'https://meet.example.com/fixture-large');

        $this->deleteJson('/ca-masters/'.$lead->ca_id)->assertOk();
    }

    public function test_demo_scheduled_requires_team_size_when_unavailable(): void
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        $lead = $this->createLeadWithTeamSize(null);
        $scheduled = now()->addDays(2)->setTime(11, 0);

        $this->postJson('/follow-ups', [
            'ca_id' => $lead->ca_id,
            'followup_type' => 'Demo Scheduled',
            'scheduled_date' => $scheduled->toDateTimeString(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['team_size']);

        $this->deleteJson('/ca-masters/'.$lead->ca_id)->assertOk();
    }

    public function test_demo_scheduled_succeeds_when_sms_api_key_cannot_be_decrypted(): void
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        $settings = \App\Models\SmsSetting::query()->first();
        if ($settings) {
            $settings->forceFill([
                'api_key' => 'invalid-ciphertext',
                'mode' => \App\Models\SmsSetting::MODE_SIMULATION,
            ])->save();
        }

        $provider = $this->createEligibleProvider('Fixture Mid', 1, 10, 'https://meet.example.com/fixture-mid');
        $lead = $this->createLeadWithTeamSize(8);
        $scheduled = now()->addDays(3)->setTime(15, 30);

        $this->postJson('/follow-ups', [
            'ca_id' => $lead->ca_id,
            'followup_type' => 'Demo Scheduled',
            'scheduled_date' => $scheduled->toDateTimeString(),
            'demo_provider_employee_id' => $provider->employee_id,
        ])->assertCreated()
            ->assertJsonPath('data.followup_type', 'Demo Scheduled');

        $this->deleteJson('/ca-masters/'.$lead->ca_id)->assertOk();
    }

    public function test_non_demo_follow_up_does_not_require_meeting_link(): void
    {
        $admin = CrmTestAccounts::admin();
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
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        $provider = $this->createEligibleProvider('Fixture Mid', 1, 10, 'https://meet.example.com/fixture-mid');
        $lead = $this->createLeadWithTeamSize(8);
        $scheduled = now()->addDays(3)->setTime(15, 30);

        $create = $this->postJson('/follow-ups', [
            'ca_id' => $lead->ca_id,
            'followup_type' => 'Demo Scheduled',
            'scheduled_date' => $scheduled->toDateTimeString(),
            'demo_provider_employee_id' => $provider->employee_id,
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
        $admin = CrmTestAccounts::admin();
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
            'meeting_link' => 'https://meet.example.com/demo-schedule-only',
            'status' => 'scheduled',
        ]);

        $this->patchJson('/follow-ups/'.$followUp->followup_id, [
            'status' => 'Completed',
        ])->assertOk()
            ->assertJsonPath('data.status', 'Completed');

        $this->deleteJson('/ca-masters/'.$lead->ca_id)->assertOk();
    }

    private function seedDemoProviders(): array
    {
        DemoProvider::query()->delete();
        $suffix = uniqid('fp', true);
        $tiers = [
            ['name' => 'Fixture Small '.$suffix, 'link' => 'https://meet.example.com/small-'.$suffix, 'min' => 1, 'max' => 1, 'sort' => 1],
            ['name' => 'Fixture Mid '.$suffix, 'link' => 'https://meet.example.com/mid-'.$suffix, 'min' => 2, 'max' => 10, 'sort' => 2],
            ['name' => 'Fixture Large '.$suffix, 'link' => 'https://meet.example.com/large-'.$suffix, 'min' => 11, 'max' => null, 'sort' => 3],
        ];

        foreach ($tiers as $tier) {
            DemoProvider::query()->create([
                'name' => $tier['name'],
                'default_meeting_link' => $tier['link'],
                'min_team_size' => $tier['min'],
                'max_team_size' => $tier['max'],
                'sort_order' => $tier['sort'],
                'is_active' => true,
                'is_demo' => true,
            ]);
        }

        return $tiers;
    }

    private function createEligibleProvider(string $name, int $min, int $max, string $link): Employee
    {
        $ts = (string) microtime(true).random_int(100, 999);

        return Employee::query()->create([
            'name' => $name,
            'email_id' => 'demo.provider.'.md5($ts.$name).'@test.local',
            'status' => 'Active',
            'work_type' => 'demo_provider',
            'demo_meeting_link' => $link,
            'demo_min_team_size' => $min,
            'demo_max_team_size' => $max,
            'active_for_demo' => true,
        ]);
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
