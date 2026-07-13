<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\DuplicateAttemptLog;
use App\Models\Employee;
use App\Models\EmployeeProductivityLog;
use App\Models\LeadAssignmentEngine;
use App\Models\LeadPhoneNumber;
use App\Models\LeadQualityHistory;
use App\Models\User;
use App\Services\Leads\EmployeeProductivityService;
use App\Services\Leads\LeadOwnershipService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class QualityControlTest extends TestCase
{
    use DatabaseTransactions;

    public function test_invalid_mobile_is_blocked_on_create(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $stateId = CaMaster::query()->whereNotNull('state_id')->value('state_id');

        $this->postJson('/ca-masters', [
            'ca_name' => 'Fake Mobile CA',
            'firm_name' => 'Fake Firm',
            'mobile_no' => '12345',
            'state_id' => $stateId,
            'status' => 'New',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['mobile_no']);
    }

    public function test_duplicate_email_is_blocked(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $stateId = CaMaster::query()->whereNotNull('state_id')->value('state_id');
        $email = 'dup-email-'.microtime(true).'@gmail.com';

        CaMaster::query()->create([
            'ca_name' => 'Email Owner',
            'firm_name' => 'Email Firm',
            'email_id' => $email,
            'normalized_email' => strtolower($email),
            'state_id' => $stateId,
            'status' => 'Active',
        ]);

        $this->postJson('/ca-masters', [
            'ca_name' => 'Dup Email CA',
            'firm_name' => 'Dup Email Firm',
            'email_id' => strtoupper($email),
            'state_id' => $stateId,
            'status' => 'New',
        ])->assertStatus(409);

        $this->assertDatabaseMissing('ca_masters', ['ca_name' => 'Dup Email CA']);
    }

    public function test_duplicate_gst_is_blocked(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $stateId = CaMaster::query()->whereNotNull('state_id')->value('state_id');
        $gst = '27AABCU9603R1ZM';

        CaMaster::query()->create([
            'ca_name' => 'GST Owner',
            'firm_name' => 'GST Firm',
            'gst_no' => $gst,
            'state_id' => $stateId,
            'status' => 'Active',
        ]);

        $this->postJson('/ca-masters', [
            'ca_name' => 'Dup GST CA',
            'firm_name' => 'Dup GST Firm',
            'gst_no' => '27-aabcu9603r1zm',
            'state_id' => $stateId,
            'status' => 'New',
        ])->assertStatus(409);
    }

    public function test_assigned_employee_can_update_lead(): void
    {
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $employeeModel = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employee);

        $lead = CaMaster::query()->whereNotNull('state_id')->firstOrFail();
        LeadAssignmentEngine::query()->updateOrCreate(
            ['ca_id' => $lead->ca_id, 'status' => 'Active'],
            ['employee_id' => $employeeModel->employee_id, 'assigned_date' => now()->toDateString()],
        );

        $this->putJson('/ca-masters/'.$lead->ca_id, [
            'status' => 'Warm',
            'rating' => 4,
        ])->assertOk();

        $lead->refresh();
        $this->assertSame('Warm', $lead->status);
    }

    public function test_manager_can_edit_any_lead(): void
    {
        $manager = User::query()->where('crm_role', 'manager')->first();
        if (! $manager) {
            $manager = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        }
        $this->actingAs($manager);

        $lead = CaMaster::query()->whereNotNull('state_id')->firstOrFail();

        $this->putJson('/ca-masters/'.$lead->ca_id, [
            'status' => 'Hot',
        ])->assertOk();
    }

    public function test_lead_ownership_service_blocks_unassigned_employee(): void
    {
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $other = Employee::query()
            ->where('status', 'Active')
            ->where('email_id', '!=', 'employee@ca.local')
            ->firstOrFail();

        $lead = CaMaster::query()->whereNotNull('state_id')->firstOrFail();
        LeadAssignmentEngine::query()->where('ca_id', $lead->ca_id)->delete();
        LeadAssignmentEngine::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $other->employee_id,
            'status' => 'Active',
            'assigned_date' => now()->toDateString(),
        ]);

        $ownership = app(LeadOwnershipService::class);
        $this->assertFalse($ownership->canEdit($employee, $lead->fresh()));
    }

    public function test_wrong_number_status_records_quality_history(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $lead = CaMaster::query()->whereNotNull('state_id')->firstOrFail();

        $this->patchJson('/ca-masters/'.$lead->ca_id.'/status', [
            'status' => 'Wrong Number',
        ])->assertOk();

        $lead->refresh();
        $this->assertTrue($lead->is_wrong_number);
        $this->assertDatabaseHas('lead_quality_histories', [
            'ca_id' => $lead->ca_id,
            'event_type' => 'wrong_number',
        ]);
    }

    public function test_productivity_score_updates_after_unique_lead_create(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $employeeModel = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $stateId = CaMaster::query()->whereNotNull('state_id')->value('state_id');
        $mobile = '9'.random_int(100000000, 999999999);

        $this->postJson('/ca-masters', [
            'ca_name' => 'QC Lead',
            'firm_name' => 'QC Firm',
            'mobile_no' => $mobile,
            'state_id' => $stateId,
            'status' => 'New',
            'created_by_employee_id' => $employeeModel->employee_id,
        ])->assertCreated();

        $lead = CaMaster::query()->where('firm_name', 'QC Firm')->firstOrFail();

        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employee);
        $metrics = app(EmployeeProductivityService::class)->employeeDailyMetrics((int) $employeeModel->employee_id);
        $this->assertGreaterThanOrEqual(1, $metrics['unique_leads']);

        $this->assertDatabaseHas('employee_productivity_logs', [
            'employee_id' => $employeeModel->employee_id,
            'log_date' => now()->toDateString(),
        ]);
    }

    public function test_duplicate_attempt_increments_productivity_penalty(): void
    {
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $employeeModel = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $stateId = CaMaster::query()->whereNotNull('state_id')->value('state_id');

        $existing = CaMaster::query()->create([
            'ca_name' => 'QC Existing',
            'firm_name' => 'QC Existing Firm',
            'mobile_no' => '9876501234',
            'normalized_mobile' => '9876501234',
            'state_id' => $stateId,
            'status' => 'Active',
        ]);
        LeadPhoneNumber::query()->create([
            'ca_id' => $existing->ca_id,
            'normalized_number' => '9876501234',
            'phone_type' => 'primary',
        ]);

        $assignedLead = CaMaster::query()->create([
            'ca_name' => 'QC Assigned',
            'firm_name' => 'QC Assigned Firm',
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
            'mobile_no' => '9876501234',
        ])->assertStatus(409);

        $log = EmployeeProductivityLog::query()
            ->where('employee_id', $employeeModel->employee_id)
            ->whereDate('log_date', now()->toDateString())
            ->first();

        $this->assertNotNull($log);
        $this->assertGreaterThanOrEqual(1, $log->duplicate_attempts);
        $this->assertDatabaseHas('duplicate_attempt_logs', [
            'employee_id' => $employeeModel->employee_id,
            'lead_id' => $existing->ca_id,
        ]);
    }
}
