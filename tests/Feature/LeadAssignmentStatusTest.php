<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AssignmentHistory;
use App\Models\CaMaster;
use App\Models\Employee;
use App\Models\LeadAssignmentEngine;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LeadAssignmentStatusTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_can_pause_and_resume_assignment(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $employee = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $lead = CaMaster::query()->create([
            'firm_name' => 'Pause Test Firm',
            'ca_name' => 'Pause CA',
            'mobile_no' => '7123456780',
            'email_id' => 'pause.test@local.test',
            'status' => 'New',
        ]);

        $assignment = LeadAssignmentEngine::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'assigned_date' => now()->toDateString(),
            'assignment_type' => 'Manual',
            'rotation_logic_used' => 'TEST',
            'priority_score' => 1,
            'target_leads' => 0,
            'achieved_leads' => 0,
            'status' => 'Active',
        ]);

        $this->patchJson('/lead-assignments/'.$assignment->assignment_id.'/status', [
            'status' => 'Paused',
        ])->assertOk()
            ->assertJsonPath('data.status', 'Paused');

        $this->assertDatabaseHas('lead_assignment_engines', [
            'assignment_id' => $assignment->assignment_id,
            'status' => 'Paused',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'Assignment Paused',
            'record_id' => (string) $assignment->assignment_id,
        ]);

        $this->assertDatabaseHas('assignment_histories', [
            'ca_id' => $lead->ca_id,
            'reason' => 'PAUSE_ASSIGNMENT',
        ]);

        $this->patchJson('/lead-assignments/'.$assignment->assignment_id.'/status', [
            'status' => 'Active',
        ])->assertOk()
            ->assertJsonPath('data.status', 'Active');

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'Assignment Resumed',
            'record_id' => (string) $assignment->assignment_id,
        ]);

        $this->assertDatabaseHas('assignment_histories', [
            'ca_id' => $lead->ca_id,
            'reason' => 'RESUME_ASSIGNMENT',
        ]);
    }

    public function test_employee_cannot_pause_assignment(): void
    {
        $employeeUser = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employeeUser);

        $employee = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $lead = CaMaster::query()->create([
            'firm_name' => 'Employee Pause Block',
            'ca_name' => 'Employee CA',
            'mobile_no' => '7123456781',
            'email_id' => 'employee.pause@local.test',
            'status' => 'New',
        ]);

        $assignment = LeadAssignmentEngine::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'assigned_date' => now()->toDateString(),
            'assignment_type' => 'Manual',
            'rotation_logic_used' => 'TEST',
            'priority_score' => 1,
            'target_leads' => 0,
            'achieved_leads' => 0,
            'status' => 'Active',
        ]);

        $this->patchJson('/lead-assignments/'.$assignment->assignment_id.'/status', [
            'status' => 'Paused',
        ])->assertForbidden();

        $this->assertSame('Active', $assignment->fresh()->status);
        $this->assertSame(0, ActivityLog::query()->where('action', 'Assignment Paused')->count());
        $this->assertSame(0, AssignmentHistory::query()->where('reason', 'PAUSE_ASSIGNMENT')->count());
    }
}
