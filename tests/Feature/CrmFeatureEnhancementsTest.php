<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\CaMaster;
use App\Models\Employee;
use App\Models\LeadAssignmentEngine;
use App\Models\LeadView;
use App\Models\SourceLead;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CrmFeatureEnhancementsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_lead_can_be_filtered_by_mobile_missing_segment(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $ts = (string) microtime(true);
        CaMaster::query()->create([
            'ca_name' => 'No Mobile '.$ts,
            'firm_name' => 'Firm '.$ts,
            'mobile_no' => null,
            'state_id' => CaMaster::query()->value('state_id'),
            'status' => 'Active',
        ]);

        $response = $this->getJson('/ca-masters?segment=mobile_missing');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data.items') ?? $response->json('data') ?? []));
    }

    public function test_lead_view_is_recorded_when_opened(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $lead = CaMaster::query()->firstOrFail();
        $before = (int) $lead->view_count;

        $this->getJson('/ca-masters/'.$lead->ca_id)->assertOk();

        $lead->refresh();
        $this->assertSame($before + 1, (int) $lead->view_count);
        $this->assertDatabaseHas('lead_views', [
            'ca_id' => $lead->ca_id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_employee_cannot_update_restricted_lead_fields(): void
    {
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $employeeModel = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employee);

        $lead = CaMaster::query()->whereNotNull('state_id')->firstOrFail();
        LeadAssignmentEngine::query()->updateOrCreate(
            ['ca_id' => $lead->ca_id, 'status' => 'Active'],
            ['employee_id' => $employeeModel->employee_id, 'assigned_date' => now()->toDateString()],
        );

        $originalFirm = $lead->firm_name;
        $originalMobile = $lead->mobile_no ?: '8888877777';
        if (! $lead->mobile_no) {
            $lead->update(['mobile_no' => $originalMobile]);
        }

        $this->putJson('/ca-masters/'.$lead->ca_id, [
            'firm_name' => 'Hacked Firm Name',
            'mobile_no' => '9876543210',
        ])->assertOk();

        $lead->refresh();
        $this->assertSame($originalFirm, $lead->firm_name);
        $this->assertSame($originalMobile, $lead->mobile_no);
    }

    public function test_employee_can_update_alternate_mobile_on_assigned_lead(): void
    {
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $employeeModel = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employee);

        $lead = CaMaster::query()->whereNotNull('state_id')->firstOrFail();
        LeadAssignmentEngine::query()->updateOrCreate(
            ['ca_id' => $lead->ca_id, 'status' => 'Active'],
            ['employee_id' => $employeeModel->employee_id, 'assigned_date' => now()->toDateString()],
        );

        $primaryMobile = '9'.substr(str_replace('.', '', (string) microtime(true)), -9);
        $lead->update([
            'mobile_no' => $primaryMobile,
            'alternate_mobile_no' => null,
        ]);

        $alternateMobile = '8'.substr(str_replace('.', '', (string) microtime(true)), -9);

        $this->putJson('/ca-masters/'.$lead->ca_id, [
            'alternate_mobile_no' => $alternateMobile,
        ])->assertOk();

        $lead->refresh();
        $this->assertSame($primaryMobile, $lead->mobile_no);
        $this->assertSame($alternateMobile, $lead->alternate_mobile_no);
    }

    public function test_employee_can_create_new_lead_and_see_it_assigned(): void
    {
        app(\App\Services\Rbac\RbacDatabaseService::class)->resetRoleToDefault('employee');
        app(\App\Services\Rbac\RbacMatrixService::class)->flushCache();

        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $employeeModel = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employee);

        $stateId = CaMaster::query()->whereNotNull('state_id')->value('state_id');
        $cityId = CaMaster::query()->where('state_id', $stateId)->whereNotNull('city_id')->value('city_id');
        $this->assertNotNull($stateId);
        $this->assertNotNull($cityId);
        $ts = (string) microtime(true);

        $response = $this->postJson('/ca-masters', [
            'ca_name' => 'New CA '.$ts,
            'firm_name' => 'New Firm '.$ts,
            'mobile_no' => '9'.random_int(100000000, 999999999),
            'email_id' => 'newlead'.$ts.'@gmail.com',
            'state_id' => $stateId,
            'city_id' => $cityId,
            'status' => 'New',
        ]);

        $response->assertCreated();
        $caId = (int) $response->json('data.ca_id');

        $this->assertDatabaseHas('lead_assignment_engines', [
            'ca_id' => $caId,
            'employee_id' => $employeeModel->employee_id,
            'status' => 'Active',
        ]);
    }

    public function test_lead_lock_blocks_another_employee_from_editing(): void
    {
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $employeeModel = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $otherEmployee = Employee::query()
            ->where('employee_id', '!=', $employeeModel->employee_id)
            ->firstOrFail();

        $lead = CaMaster::query()->whereNotNull('state_id')->firstOrFail();
        LeadAssignmentEngine::query()->updateOrCreate(
            ['ca_id' => $lead->ca_id, 'status' => 'Active'],
            ['employee_id' => $employeeModel->employee_id, 'assigned_date' => now()->toDateString()],
        );

        $lead->update([
            'locked_by' => $otherEmployee->employee_id,
            'locked_at' => now(),
        ]);

        $this->actingAs($employee);

        $this->postJson('/ca-masters/'.$lead->ca_id.'/lock')
            ->assertStatus(423)
            ->assertJsonPath('errors.lock.is_locked_by_other', true);

        $this->putJson('/ca-masters/'.$lead->ca_id, [
            'email_id' => 'locked-test@gmail.com',
        ])->assertStatus(423);
    }

    public function test_lead_lock_is_released_after_successful_update(): void
    {
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $employeeModel = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employee);

        $lead = CaMaster::query()->whereNotNull('state_id')->firstOrFail();
        LeadAssignmentEngine::query()->updateOrCreate(
            ['ca_id' => $lead->ca_id, 'status' => 'Active'],
            ['employee_id' => $employeeModel->employee_id, 'assigned_date' => now()->toDateString()],
        );

        $this->postJson('/ca-masters/'.$lead->ca_id.'/lock')->assertOk();

        $lead->refresh();
        $this->assertSame((int) $employeeModel->employee_id, (int) $lead->locked_by);

        $this->putJson('/ca-masters/'.$lead->ca_id, [
            'email_id' => 'released-lock@gmail.com',
        ])->assertOk();

        $lead->refresh();
        $this->assertNull($lead->locked_by);
        $this->assertNull($lead->locked_at);
    }

    public function test_admin_bypasses_lead_lock(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $employeeModel = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();

        $lead = CaMaster::query()->whereNotNull('state_id')->firstOrFail();
        $lead->update([
            'locked_by' => $employeeModel->employee_id,
            'locked_at' => now(),
        ]);

        $this->actingAs($admin);

        $this->postJson('/ca-masters/'.$lead->ca_id.'/lock')->assertOk();

        $this->putJson('/ca-masters/'.$lead->ca_id, [
            'firm_name' => 'Admin Updated Firm',
        ])->assertOk();

        $lead->refresh();
        $this->assertSame('Admin Updated Firm', $lead->firm_name);
    }

    public function test_stale_lead_lock_expires_after_ttl(): void
    {
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $employeeModel = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $otherEmployee = Employee::query()
            ->where('employee_id', '!=', $employeeModel->employee_id)
            ->firstOrFail();

        $lead = CaMaster::query()->whereNotNull('state_id')->firstOrFail();
        LeadAssignmentEngine::query()->updateOrCreate(
            ['ca_id' => $lead->ca_id, 'status' => 'Active'],
            ['employee_id' => $employeeModel->employee_id, 'assigned_date' => now()->toDateString()],
        );

        $lead->update([
            'locked_by' => $otherEmployee->employee_id,
            'locked_at' => now()->subMinutes(11),
        ]);

        $this->actingAs($employee);

        $this->postJson('/ca-masters/'.$lead->ca_id.'/lock')->assertOk();

        $lead->refresh();
        $this->assertSame((int) $employeeModel->employee_id, (int) $lead->locked_by);
    }

    public function test_employee_sensitive_status_change_requires_approval(): void
    {
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $employeeModel = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employee);

        $lead = CaMaster::query()->firstOrFail();
        LeadAssignmentEngine::query()->updateOrCreate(
            ['ca_id' => $lead->ca_id, 'status' => 'Active'],
            ['employee_id' => $employeeModel->employee_id, 'assigned_date' => now()->toDateString()],
        );

        $this->patchJson('/ca-masters/'.$lead->ca_id.'/status', ['status' => 'Lost'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_employee_can_submit_approval_request_and_manager_can_approve(): void
    {
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $manager = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $employeeModel = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();

        $lead = CaMaster::query()->where('status', '!=', 'Lost')->firstOrFail();
        LeadAssignmentEngine::query()->updateOrCreate(
            ['ca_id' => $lead->ca_id, 'status' => 'Active'],
            ['employee_id' => $employeeModel->employee_id, 'assigned_date' => now()->toDateString()],
        );

        $this->actingAs($employee);

        $create = $this->postJson('/approval-requests', [
            'request_type' => 'lead_status_change',
            'ca_id' => $lead->ca_id,
            'payload' => ['status' => 'Lost'],
        ]);

        $create->assertCreated();
        $requestId = $create->json('data.approval_request_id');

        $this->actingAs($manager);

        $this->postJson('/approval-requests/'.$requestId.'/approve', ['remarks' => 'Approved by manager'])
            ->assertOk();

        $lead->refresh();
        $this->assertSame('Lost', $lead->status);
        $this->assertSame('approved', ApprovalRequest::query()->find($requestId)?->status);
    }

    public function test_lead_tags_and_priority_can_be_set(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $lead = CaMaster::query()->whereNotNull('state_id')->firstOrFail();

        $this->putJson('/ca-masters/'.$lead->ca_id, [
            'lead_tags' => ['Hot', 'Interested'],
            'priority' => 'High',
            'research_status' => 'Pending Research',
        ])->assertOk();

        $lead->refresh();
        $this->assertSame(['Hot', 'Interested'], $lead->lead_tags);
        $this->assertSame('High', $lead->priority);
        $this->assertSame('Pending Research', $lead->research_status);
    }

    public function test_employee_can_fill_empty_email_once_then_locked(): void
    {
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $employeeModel = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employee);

        $lead = CaMaster::query()->whereNotNull('state_id')->firstOrFail();
        LeadAssignmentEngine::query()->updateOrCreate(
            ['ca_id' => $lead->ca_id, 'status' => 'Active'],
            ['employee_id' => $employeeModel->employee_id, 'assigned_date' => now()->toDateString()],
        );

        $lead->update(['email_id' => null]);

        $this->putJson('/ca-masters/'.$lead->ca_id, [
            'email_id' => 'employee-filled@gmail.com',
        ])->assertOk();

        $lead->refresh();
        $this->assertSame('employee-filled@gmail.com', $lead->email_id);

        $this->putJson('/ca-masters/'.$lead->ca_id, [
            'email_id' => 'hacked@gmail.com',
        ])->assertOk();

        $lead->refresh();
        $this->assertSame('employee-filled@gmail.com', $lead->email_id);
    }

    public function test_employee_can_fill_empty_primary_mobile_once_then_locked(): void
    {
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $employeeModel = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employee);

        $lead = CaMaster::query()->whereNotNull('state_id')->firstOrFail();
        LeadAssignmentEngine::query()->updateOrCreate(
            ['ca_id' => $lead->ca_id, 'status' => 'Active'],
            ['employee_id' => $employeeModel->employee_id, 'assigned_date' => now()->toDateString()],
        );

        $lead->update(['mobile_no' => null]);

        $this->putJson('/ca-masters/'.$lead->ca_id, [
            'mobile_no' => '9123456780',
        ])->assertOk();

        $lead->refresh();
        $this->assertSame('9123456780', $lead->mobile_no);

        $this->putJson('/ca-masters/'.$lead->ca_id, [
            'mobile_no' => '9876543210',
        ])->assertOk();

        $lead->refresh();
        $this->assertSame('9123456780', $lead->mobile_no);
    }

    public function test_employee_cannot_change_assigned_executive(): void
    {
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $employeeModel = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $otherExecutive = Employee::query()
            ->where('employee_id', '!=', $employeeModel->employee_id)
            ->where('status', 'Active')
            ->firstOrFail();

        $this->actingAs($employee);

        $lead = CaMaster::query()->whereNotNull('state_id')->firstOrFail();
        LeadAssignmentEngine::query()->updateOrCreate(
            ['ca_id' => $lead->ca_id, 'status' => 'Active'],
            ['employee_id' => $employeeModel->employee_id, 'assigned_date' => now()->toDateString()],
        );

        $this->putJson('/ca-masters/'.$lead->ca_id, [
            'executive_id' => $otherExecutive->employee_id,
        ])->assertOk();

        $this->assertDatabaseHas('lead_assignment_engines', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employeeModel->employee_id,
            'status' => 'Active',
        ]);
    }

    public function test_lead_resource_includes_employee_locked_fields(): void
    {
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $employeeModel = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employee);

        $lead = CaMaster::query()->whereNotNull('state_id')->firstOrFail();
        LeadAssignmentEngine::query()->updateOrCreate(
            ['ca_id' => $lead->ca_id, 'status' => 'Active'],
            ['employee_id' => $employeeModel->employee_id, 'assigned_date' => now()->toDateString()],
        );

        $response = $this->getJson('/ca-masters/'.$lead->ca_id)->assertOk();

        $locked = $response->json('data.employee_locked_fields') ?? [];
        $this->assertContains('ca_name', $locked);
        $this->assertNotContains('status', $locked);
        $this->assertNotContains('rating', $locked);
        $this->assertNotContains('source_id', $locked);
        $this->assertContains('executive_id', $locked);
        $this->assertNotContains('alternate_mobile_no', $locked);
    }

    public function test_employee_can_update_rating_status_and_source_on_assigned_lead(): void
    {
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $employeeModel = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employee);

        $lead = CaMaster::query()->whereNotNull('state_id')->firstOrFail();
        $sourceId = SourceLead::query()->value('source_id');
        $this->assertNotNull($sourceId);

        LeadAssignmentEngine::query()->updateOrCreate(
            ['ca_id' => $lead->ca_id, 'status' => 'Active'],
            ['employee_id' => $employeeModel->employee_id, 'assigned_date' => now()->toDateString()],
        );

        $lead->update([
            'rating' => 2,
            'status' => 'Warm',
            'is_newly_established' => false,
            'source_id' => null,
        ]);

        $this->putJson('/ca-masters/'.$lead->ca_id, [
            'rating' => 5,
            'status' => 'Hot',
            'is_newly_established' => true,
            'source_id' => $sourceId,
        ])->assertOk();

        $lead->refresh();
        $this->assertSame(5, (int) $lead->rating);
        $this->assertSame('Hot', $lead->status);
        $this->assertTrue((bool) $lead->is_newly_established);
        $this->assertSame((int) $sourceId, (int) $lead->source_id);
    }

    public function test_employee_cannot_update_executive_assignment(): void
    {
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $employeeModel = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $otherExecutive = Employee::query()
            ->where('status', 'Active')
            ->where('employee_id', '!=', $employeeModel->employee_id)
            ->firstOrFail();
        $this->actingAs($employee);

        $lead = CaMaster::query()->whereNotNull('state_id')->firstOrFail();
        LeadAssignmentEngine::query()->updateOrCreate(
            ['ca_id' => $lead->ca_id, 'status' => 'Active'],
            ['employee_id' => $employeeModel->employee_id, 'assigned_date' => now()->toDateString()],
        );

        $this->putJson('/ca-masters/'.$lead->ca_id, [
            'executive_id' => $otherExecutive->employee_id,
        ])->assertOk();

        $this->assertSame(
            $employeeModel->employee_id,
            LeadAssignmentEngine::query()
                ->where('ca_id', $lead->ca_id)
                ->where('status', 'Active')
                ->value('employee_id'),
        );
    }

    public function test_lead_update_with_executive_id_creates_assignment(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $employee = Employee::query()->where('status', 'Active')->firstOrFail();
        $this->actingAs($admin);

        $lead = CaMaster::query()->whereNotNull('state_id')->firstOrFail();
        LeadAssignmentEngine::query()->where('ca_id', $lead->ca_id)->delete();

        $this->putJson('/ca-masters/'.$lead->ca_id, [
            'executive_id' => $employee->employee_id,
            'firm_name' => $lead->firm_name,
            'ca_name' => $lead->ca_name,
            'state_id' => $lead->state_id,
        ])->assertOk();

        $this->assertDatabaseHas('lead_assignment_engines', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'status' => 'Active',
        ]);
    }
}
