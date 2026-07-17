<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\City;
use App\Models\Employee;
use App\Models\State;
use App\Models\User;
use App\Services\Demo\DemoProviderEligibilityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class EmployeeDemoAssignmentTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsAdmin(): User
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        return $admin;
    }

    public function test_can_create_calling_demo_provider_and_both_employees(): void
    {
        $this->actingAsAdmin();
        $ts = (string) microtime(true);

        $calling = $this->postJson('/employees', [
            'name' => 'Calling Emp '.$ts,
            'email_id' => "calling.{$ts}@test.local",
            'mobile_no' => '8'.substr(str_replace('.', '', $ts), -9),
            'crm_role' => 'employee',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'work_type' => 'calling',
            'status' => 'Active',
        ])->assertCreated();

        $this->assertSame('calling', $calling->json('data.work_type'));
        $this->assertFalse((bool) $calling->json('data.active_for_demo'));

        $provider = $this->postJson('/employees', [
            'name' => 'Demo Emp '.$ts,
            'email_id' => "demo.{$ts}@test.local",
            'mobile_no' => '7'.substr(str_replace('.', '', $ts), -9),
            'crm_role' => 'employee',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'work_type' => 'demo_provider',
            'demo_meeting_link' => 'https://meet.google.com/demo-emp',
            'demo_min_team_size' => 1,
            'demo_max_team_size' => 25,
            'active_for_demo' => true,
            'status' => 'Active',
        ])->assertCreated();

        $this->assertSame('demo_provider', $provider->json('data.work_type'));
        $this->assertSame(1, $provider->json('data.demo_min_team_size'));
        $this->assertSame(25, $provider->json('data.demo_max_team_size'));
        $this->assertTrue((bool) $provider->json('data.active_for_demo'));

        $both = $this->postJson('/employees', [
            'name' => 'Both Emp '.$ts,
            'email_id' => "both.{$ts}@test.local",
            'mobile_no' => '6'.substr(str_replace('.', '', $ts), -9),
            'crm_role' => 'employee',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'work_type' => 'both',
            'demo_meeting_link' => 'https://meet.google.com/both-emp',
            'demo_min_team_size' => 10,
            'demo_max_team_size' => 100,
            'active_for_demo' => true,
            'status' => 'Active',
        ])->assertCreated();

        $this->assertSame('both', $both->json('data.work_type'));
    }

    public function test_demo_fields_are_conditionally_validated(): void
    {
        $this->actingAsAdmin();
        $ts = (string) microtime(true);

        $this->postJson('/employees', [
            'name' => 'Invalid Demo '.$ts,
            'email_id' => "invalid.demo.{$ts}@test.local",
            'mobile_no' => '5'.substr(str_replace('.', '', $ts), -9),
            'crm_role' => 'employee',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'work_type' => 'demo_provider',
            'demo_min_team_size' => 50,
            'demo_max_team_size' => 10,
            'active_for_demo' => true,
            'status' => 'Active',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['demo_meeting_link', 'demo_min_team_size']);
    }

    public function test_team_size_filtering_and_exclusions(): void
    {
        $this->actingAsAdmin();
        $ts = (string) microtime(true);

        $eligible = Employee::query()->create([
            'name' => 'Priya Sharma '.$ts,
            'email_id' => "priya.{$ts}@test.local",
            'status' => 'Active',
            'work_type' => 'demo_provider',
            'demo_meeting_link' => 'https://meet.google.com/priya',
            'demo_min_team_size' => 701,
            'demo_max_team_size' => 725,
            'active_for_demo' => true,
        ]);

        $large = Employee::query()->create([
            'name' => 'Rahul Verma '.$ts,
            'email_id' => "rahul.{$ts}@test.local",
            'status' => 'Active',
            'work_type' => 'both',
            'demo_meeting_link' => 'https://meet.google.com/rahul',
            'demo_min_team_size' => 720,
            'demo_max_team_size' => 800,
            'active_for_demo' => true,
        ]);

        Employee::query()->create([
            'name' => 'Inactive Demo '.$ts,
            'email_id' => "inactive.{$ts}@test.local",
            'status' => 'Active',
            'work_type' => 'demo_provider',
            'demo_meeting_link' => 'https://meet.google.com/inactive',
            'demo_min_team_size' => 701,
            'demo_max_team_size' => 800,
            'active_for_demo' => false,
        ]);

        Employee::query()->create([
            'name' => 'Calling Only '.$ts,
            'email_id' => "calling.only.{$ts}@test.local",
            'status' => 'Active',
            'work_type' => 'calling',
            'active_for_demo' => true,
            'demo_min_team_size' => 701,
            'demo_max_team_size' => 800,
        ]);

        $service = app(DemoProviderEligibilityService::class);
        $forOverlap = $service->eligibleForTeamSize(720)->pluck('employee_id')->all();

        $this->assertEqualsCanonicalizing(
            [$eligible->employee_id, $large->employee_id],
            array_values(array_intersect($forOverlap, [$eligible->employee_id, $large->employee_id])),
        );
        $this->assertCount(2, array_intersect($forOverlap, [$eligible->employee_id, $large->employee_id]));

        $response = $this->getJson('/employees/demo-providers?team_size=720')->assertOk();
        $ids = collect($response->json('data'))->pluck('employee_id')->all();
        $this->assertEqualsCanonicalizing(
            [$eligible->employee_id, $large->employee_id],
            array_values(array_intersect($ids, [$eligible->employee_id, $large->employee_id])),
        );

        $labels = collect($response->json('data'))
            ->whereIn('employee_id', [$eligible->employee_id, $large->employee_id])
            ->pluck('label')
            ->implode(' | ');
        $this->assertStringContainsString('701 to 725', $labels);
        $this->assertStringContainsString('720 to 800', $labels);

        $smallOnly = $service->eligibleForTeamSize(705)->pluck('employee_id')->all();
        $this->assertContains($eligible->employee_id, $smallOnly);
        $this->assertNotContains($large->employee_id, $smallOnly);
    }

    public function test_demo_scheduled_follow_up_saves_provider_and_link(): void
    {
        $this->actingAsAdmin();
        $ts = (string) microtime(true);

        $provider = Employee::query()->create([
            'name' => 'Priya Sharma',
            'email_id' => "provider.save.{$ts}@test.local",
            'status' => 'Active',
            'work_type' => 'both',
            'demo_meeting_link' => 'https://meet.google.com/priya-save',
            'demo_min_team_size' => 1,
            'demo_max_team_size' => 50,
            'active_for_demo' => true,
        ]);

        $lead = $this->createLeadWithTeamSize(20);
        $scheduled = now()->addDays(3)->setTime(15, 30);

        $response = $this->postJson('/follow-ups', [
            'ca_id' => $lead->ca_id,
            'followup_type' => 'Demo Scheduled',
            'scheduled_date' => $scheduled->toDateTimeString(),
            'team_size' => 20,
            'demo_provider_employee_id' => $provider->employee_id,
            'status' => 'Pending',
        ])->assertCreated();

        $response->assertJsonPath('data.team_size', 20)
            ->assertJsonPath('data.demo_provider_employee_id', $provider->employee_id)
            ->assertJsonPath('data.demo_provider_name', 'Priya Sharma')
            ->assertJsonPath('data.meeting_link', 'https://meet.google.com/priya-save');

        $this->deleteJson('/ca-masters/'.$lead->ca_id)->assertOk();
    }

    public function test_no_matching_provider_returns_clear_validation_message(): void
    {
        $this->actingAsAdmin();

        $lead = $this->createLeadWithTeamSize(99991);
        $scheduled = now()->addDays(2)->setTime(11, 0);

        $this->postJson('/follow-ups', [
            'ca_id' => $lead->ca_id,
            'followup_type' => 'Demo Scheduled',
            'scheduled_date' => $scheduled->toDateTimeString(),
            'team_size' => 99991,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['demo_provider_employee_id']);

        $this->deleteJson('/ca-masters/'.$lead->ca_id)->assertOk();
    }

    private function createLeadWithTeamSize(?int $teamSize): CaMaster
    {
        $ts = (string) microtime(true);
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $mobile = '9'.substr(str_replace('.', '', $ts), -9);

        $payload = [
            'firm_name' => 'Demo Assign Firm '.$ts,
            'ca_name' => 'Demo Assign CA',
            'mobile_no' => $mobile,
            'email_id' => "demo.assign.{$ts}@test.local",
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
