<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Models\CaMaster;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Ensures managers can assign via POST /lead-assignments (permission: assign, not create).
 */
class ManagerAssignmentPermissionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_manager_can_create_single_lead_assignment(): void
    {
        $manager = CrmTestAccounts::manager();
        $employee = CrmTestAccounts::employee();
        $suffix = (string) random_int(100000, 999999);

        $lead = CaMaster::query()->create([
            'firm_name' => 'Mgr Assign '.$suffix,
            'ca_name' => 'Mgr CA',
            'mobile_no' => '7'.substr(str_pad($suffix, 9, '0'), -9),
            'email_id' => "mgr.assign.{$suffix}@test.local",
            'status' => 'New',
        ]);

        $this->actingAs($manager)
            ->postJson('/lead-assignments', [
                'ca_id' => $lead->ca_id,
                'employee_id' => $employee->employee_id,
                'assignment_type' => 'Manual',
                'reason' => 'MANAGER_ASSIGN_PERM',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('lead_assignment_engines', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
        ]);
    }

    public function test_employee_cannot_create_lead_assignment(): void
    {
        $employeeUser = CrmTestAccounts::employeeUser();
        $employee = CrmTestAccounts::employee();
        $suffix = (string) random_int(100000, 999999);

        $lead = CaMaster::query()->create([
            'firm_name' => 'Emp Assign Deny '.$suffix,
            'ca_name' => 'Deny CA',
            'mobile_no' => '6'.substr(str_pad($suffix, 9, '0'), -9),
            'email_id' => "emp.deny.{$suffix}@test.local",
            'status' => 'New',
        ]);

        $this->actingAs($employeeUser)
            ->postJson('/lead-assignments', [
                'ca_id' => $lead->ca_id,
                'employee_id' => $employee->employee_id,
                'assignment_type' => 'Manual',
                'reason' => 'SHOULD_FAIL',
            ])
            ->assertForbidden();
    }
}
