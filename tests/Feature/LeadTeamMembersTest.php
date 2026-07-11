<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\Employee;
use App\Models\LeadAssignmentEngine;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LeadTeamMembersTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsAdmin(): User
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        return $admin;
    }

    public function test_ca_master_listing_includes_team_member_summary(): void
    {
        $this->actingAsAdmin();

        $lead = CaMaster::query()->firstOrFail();
        $employee = Employee::query()->where('status', 'Active')->firstOrFail();

        LeadAssignmentEngine::query()->updateOrCreate(
            ['ca_id' => $lead->ca_id, 'status' => 'Active'],
            [
                'employee_id' => $employee->employee_id,
                'assigned_date' => now()->toDateString(),
                'assignment_type' => 'Manual',
            ],
        );

        $response = $this->getJson('/ca-masters/'.$lead->ca_id);

        $response->assertOk();
        $item = $response->json('data');
        $this->assertSame(1, (int) ($item['team_members_count'] ?? 0));
        $this->assertContains($employee->name, $item['team_member_names'] ?? []);
        $this->assertSame((int) $employee->employee_id, (int) ($item['lead_owner_id'] ?? 0));
    }

    public function test_unassigned_lead_reports_zero_team_members(): void
    {
        $this->actingAsAdmin();

        $ts = (string) microtime(true);
        $lead = CaMaster::query()->create([
            'ca_name' => 'Unassigned CA '.$ts,
            'firm_name' => 'Unassigned Firm '.$ts,
            'mobile_no' => '9'.substr(str_replace('.', '', $ts), -9),
            'state_id' => CaMaster::query()->value('state_id'),
            'status' => 'New',
        ]);

        LeadAssignmentEngine::query()
            ->where('ca_id', $lead->ca_id)
            ->where('status', 'Active')
            ->delete();

        $response = $this->getJson('/ca-masters/'.$lead->ca_id);

        $response->assertOk()
            ->assertJsonPath('data.team_members_count', 0)
            ->assertJsonPath('data.team_member_names', []);
    }

    public function test_team_members_detail_endpoint_returns_owner_and_availability(): void
    {
        $this->actingAsAdmin();

        $lead = CaMaster::query()->firstOrFail();
        $employee = Employee::query()->where('status', 'Active')->firstOrFail();

        LeadAssignmentEngine::query()->updateOrCreate(
            ['ca_id' => $lead->ca_id, 'status' => 'Active'],
            [
                'employee_id' => $employee->employee_id,
                'assigned_date' => now()->toDateString(),
                'assignment_type' => 'Manual',
            ],
        );

        $response = $this->getJson('/ca-masters/'.$lead->ca_id.'/team-members');

        $response->assertOk()
            ->assertJsonPath('data.team_members_count', 1)
            ->assertJsonPath('data.members.0.name', $employee->name)
            ->assertJsonPath('data.members.0.is_lead_owner', true)
            ->assertJsonStructure([
                'data' => [
                    'ca_id',
                    'firm_name',
                    'team_members_count',
                    'members' => [[
                        'assignment_id',
                        'employee_id',
                        'name',
                        'role',
                        'availability_status',
                        'assigned_date',
                        'is_lead_owner',
                        'is_active',
                    ]],
                ],
            ]);
    }

    public function test_team_members_detail_marks_inactive_employee_as_offline(): void
    {
        $this->actingAsAdmin();

        $lead = CaMaster::query()->firstOrFail();
        $employee = Employee::query()->firstOrFail();
        $employee->update(['status' => 'Inactive']);

        LeadAssignmentEngine::query()->updateOrCreate(
            ['ca_id' => $lead->ca_id, 'status' => 'Active'],
            [
                'employee_id' => $employee->employee_id,
                'assigned_date' => now()->toDateString(),
                'assignment_type' => 'Manual',
            ],
        );

        $response = $this->getJson('/ca-masters/'.$lead->ca_id.'/team-members');

        $response->assertOk()
            ->assertJsonPath('data.members.0.availability_status', 'Offline')
            ->assertJsonPath('data.members.0.is_active', false);
    }
}
