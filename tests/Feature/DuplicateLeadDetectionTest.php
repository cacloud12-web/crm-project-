<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\DuplicateAttemptLog;
use App\Models\Employee;
use App\Models\LeadAssignmentEngine;
use App\Models\LeadPhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DuplicateLeadDetectionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_phone_normalization_treats_country_code_variants_as_same(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $stateId = CaMaster::query()->whereNotNull('state_id')->value('state_id');
        $this->assertNotNull($stateId);

        $mobile = '9'.(string) random_int(100000000, 999999999);

        $this->postJson('/ca-masters', [
            'ca_name' => 'Dup Test CA',
            'firm_name' => 'Dup Test Firm',
            'mobile_no' => '+91-'.$mobile,
            'state_id' => $stateId,
            'status' => 'New',
        ])->assertCreated();

        $response = $this->getJson('/ca-masters/check-duplicate?mobile=0'.$mobile);
        $response->assertStatus(409)
            ->assertJsonPath('errors.duplicate.existing_lead.ca_name', 'Dup Test CA');
    }

    public function test_duplicate_create_is_blocked_and_logged_for_employee(): void
    {
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $employeeModel = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();

        $stateId = CaMaster::query()->whereNotNull('state_id')->value('state_id');
        $existing = CaMaster::query()->create([
            'ca_name' => 'Existing CA',
            'firm_name' => 'Existing Firm',
            'mobile_no' => '9123456789',
            'normalized_mobile' => '9123456789',
            'state_id' => $stateId,
            'status' => 'Active',
            'created_by_employee_id' => Employee::query()->value('employee_id'),
        ]);
        LeadPhoneNumber::query()->create([
            'ca_id' => $existing->ca_id,
            'normalized_number' => '9123456789',
            'phone_type' => 'primary',
        ]);

        $assignedLead = CaMaster::query()->create([
            'ca_name' => 'Assigned CA',
            'firm_name' => 'Assigned Firm',
            'mobile_no' => null,
            'state_id' => $stateId,
            'status' => 'New',
        ]);
        LeadAssignmentEngine::query()->create([
            'ca_id' => $assignedLead->ca_id,
            'employee_id' => $employeeModel->employee_id,
            'assigned_date' => now()->toDateString(),
            'status' => 'Active',
        ]);

        $this->actingAs($employee);

        $this->putJson('/ca-masters/'.$assignedLead->ca_id, [
            'mobile_no' => '9123456789',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Duplicate Number Found. This number already exists.');

        $this->assertDatabaseHas('duplicate_attempt_logs', [
            'employee_id' => $employeeModel->employee_id,
            'lead_id' => $existing->ca_id,
            'attempted_mobile' => '9123456789',
        ]);

        $this->assertDatabaseMissing('ca_masters', [
            'ca_name' => 'Duplicate CA',
        ]);
    }

    public function test_alternate_mobile_duplicate_is_blocked(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $stateId = CaMaster::query()->whereNotNull('state_id')->value('state_id');
        $existing = CaMaster::query()->create([
            'ca_name' => 'Alt Existing',
            'firm_name' => 'Alt Firm',
            'mobile_no' => '9111222333',
            'normalized_mobile' => '9111222333',
            'state_id' => $stateId,
            'status' => 'Active',
        ]);
        LeadPhoneNumber::query()->create([
            'ca_id' => $existing->ca_id,
            'normalized_number' => '9111222333',
            'phone_type' => 'primary',
        ]);

        $this->postJson('/ca-masters', [
            'ca_name' => 'New CA',
            'firm_name' => 'New Firm',
            'alternate_mobile_no' => '9111222333',
            'state_id' => $stateId,
            'status' => 'New',
        ])->assertStatus(409);
    }

    public function test_lead_can_be_created_when_previous_owner_was_soft_deleted(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $stateId = CaMaster::query()->whereNotNull('state_id')->value('state_id');
        $mobile = '9'.(string) random_int(100000000, 999999999);

        $deleted = CaMaster::query()->create([
            'ca_name' => 'Deleted CA',
            'firm_name' => 'Deleted Firm',
            'mobile_no' => $mobile,
            'normalized_mobile' => $mobile,
            'state_id' => $stateId,
            'status' => 'Active',
        ]);
        LeadPhoneNumber::query()->create([
            'ca_id' => $deleted->ca_id,
            'normalized_number' => $mobile,
            'phone_type' => 'primary',
        ]);
        $deleted->delete();

        $this->postJson('/ca-masters', [
            'ca_name' => 'Reused CA',
            'firm_name' => 'Reused Firm',
            'mobile_no' => $mobile,
            'state_id' => $stateId,
            'status' => 'New',
        ])->assertCreated()
            ->assertJsonPath('data.ca_name', 'Reused CA');

        $this->assertDatabaseHas('lead_phone_numbers', [
            'normalized_number' => $mobile,
        ]);
        $this->assertDatabaseHas('ca_masters', [
            'ca_name' => 'Reused CA',
            'normalized_mobile' => $mobile,
        ]);
    }

    public function test_soft_delete_removes_lead_phone_registry(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $stateId = CaMaster::query()->whereNotNull('state_id')->value('state_id');
        $mobile = '9'.(string) random_int(100000000, 999999999);

        $existing = CaMaster::query()->create([
            'ca_name' => 'Delete Me CA',
            'firm_name' => 'Delete Me Firm',
            'mobile_no' => $mobile,
            'normalized_mobile' => $mobile,
            'state_id' => $stateId,
            'status' => 'Active',
        ]);
        LeadPhoneNumber::query()->create([
            'ca_id' => $existing->ca_id,
            'normalized_number' => $mobile,
            'phone_type' => 'primary',
        ]);

        $this->deleteJson('/ca-masters/'.$existing->ca_id)->assertOk();

        $this->assertDatabaseMissing('lead_phone_numbers', [
            'ca_id' => $existing->ca_id,
        ]);
    }
}
