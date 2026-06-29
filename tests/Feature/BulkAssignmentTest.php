<?php

namespace Tests\Feature;

use App\Models\AssignmentHistory;
use App\Models\BulkAction;
use App\Models\CaMaster;
use App\Models\City;
use App\Models\Employee;
use App\Models\LeadAssignmentEngine;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BulkAssignmentTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsAdmin(): User
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        return $admin;
    }

    private function createLead(int $cityId, int $stateId, string $suffix): CaMaster
    {
        return CaMaster::query()->create([
            'firm_name' => 'Bulk Test Firm '.$suffix,
            'ca_name' => 'Bulk CA '.$suffix,
            'mobile_no' => '9'.substr(str_pad((string) random_int(100000000, 999999999), 9, '0'), -9),
            'email_id' => 'bulk.'.$suffix.'@test.local',
            'city_id' => $cityId,
            'state_id' => $stateId,
            'status' => 'Hot',
            'rating' => 4,
            'team_size' => 5,
        ]);
    }

    private function createEmployee(string $name, int $cityId, string $status = 'Active'): Employee
    {
        $suffix = str_replace(' ', '.', strtolower($name)).random_int(100, 999);

        return Employee::query()->create([
            'name' => $name,
            'email_id' => $suffix.'@bulk.test',
            'mobile_no' => '8'.substr(str_pad((string) random_int(100000000, 999999999), 9, '0'), -9),
            'role' => 'Sales Executive',
            'city_id' => $cityId,
            'status' => $status,
            'date_of_joining' => now()->toDateString(),
        ]);
    }

    public function test_bulk_catalog_endpoints_return_paginated_data(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/lead-assignments/bulk/leads?per_page=5')
            ->assertOk()
            ->assertJsonStructure(['success', 'data' => ['items', 'pagination' => ['page', 'total']]]);

        $this->getJson('/lead-assignments/bulk/employees?per_page=5')
            ->assertOk()
            ->assertJsonStructure(['success', 'data' => ['items', 'pagination']]);

        $this->getJson('/lead-assignments/bulk/batches?per_page=5')
            ->assertOk()
            ->assertJsonStructure(['success', 'data' => ['items', 'pagination' => ['page', 'total']]]);
    }

    public function test_bulk_batch_assignment_preview_and_confirm(): void
    {
        $this->actingAsAdmin();
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $employee = $this->createEmployee('Batch Assign Exec', $city->city_id);

        $bulkAction = BulkAction::query()->create([
            'action_type' => 'ca_master_import',
            'file_name' => 'eastprop_first_150.csv',
            'total_records' => 2,
            'processed_records' => 2,
            'success_records' => 2,
            'duplicate_records' => 0,
            'skipped_records' => 0,
            'failed_records' => 0,
            'imported_by' => 'Super Admin',
            'status' => 'Completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $leadA = $this->createLead($city->city_id, $state->state_id, 'batch-a');
        $leadB = $this->createLead($city->city_id, $state->state_id, 'batch-b');
        $leadA->update(['bulk_action_id' => $bulkAction->bulk_action_id]);
        $leadB->update(['bulk_action_id' => $bulkAction->bulk_action_id]);

        $this->getJson('/lead-assignments/bulk/batches?per_page=10')
            ->assertOk()
            ->assertJsonFragment(['file_name' => 'eastprop_first_150.csv'])
            ->assertJsonFragment(['total_leads' => 2])
            ->assertJsonFragment(['unassigned_leads' => 2]);

        $preview = $this->postJson('/lead-assignments/bulk', [
            'bulk_action_id' => $bulkAction->bulk_action_id,
            'employee_ids' => [$employee->employee_id],
            'assignment_mode' => 'manual',
            'preview' => true,
        ]);
        $preview->assertOk()
            ->assertJsonPath('data.total_leads', 2)
            ->assertJsonPath('data.preview', true);

        $this->postJson('/lead-assignments/bulk', [
            'bulk_action_id' => $bulkAction->bulk_action_id,
            'employee_ids' => [$employee->employee_id],
            'assignment_mode' => 'manual',
            'preview' => false,
        ])->assertOk();

        $this->assertDatabaseHas('lead_assignment_engines', [
            'ca_id' => $leadA->ca_id,
            'employee_id' => $employee->employee_id,
            'status' => 'Active',
        ]);
        $this->assertDatabaseHas('lead_assignment_engines', [
            'ca_id' => $leadB->ca_id,
            'employee_id' => $employee->employee_id,
            'status' => 'Active',
        ]);
    }

    public function test_bulk_batch_assignment_respects_unassigned_filter(): void
    {
        $this->actingAsAdmin();
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $employee = $this->createEmployee('Batch Filter Exec', $city->city_id);

        $bulkAction = BulkAction::query()->create([
            'action_type' => 'ca_master_import',
            'file_name' => 'mixed_assignment.csv',
            'total_records' => 2,
            'processed_records' => 2,
            'success_records' => 2,
            'imported_by' => 'Super Admin',
            'status' => 'Completed',
            'completed_at' => now(),
        ]);

        $unassigned = $this->createLead($city->city_id, $state->state_id, 'batch-unassigned');
        $assigned = $this->createLead($city->city_id, $state->state_id, 'batch-assigned');
        $unassigned->update(['bulk_action_id' => $bulkAction->bulk_action_id]);
        $assigned->update(['bulk_action_id' => $bulkAction->bulk_action_id]);

        LeadAssignmentEngine::query()->create([
            'ca_id' => $assigned->ca_id,
            'employee_id' => $employee->employee_id,
            'status' => 'Active',
            'assigned_date' => now()->toDateString(),
            'assignment_type' => 'Manual',
            'reason' => 'MANUAL_ASSIGN',
        ]);

        $preview = $this->postJson('/lead-assignments/bulk', [
            'bulk_action_id' => $bulkAction->bulk_action_id,
            'assignment' => 'unassigned',
            'employee_ids' => [$employee->employee_id],
            'assignment_mode' => 'manual',
            'preview' => true,
        ]);

        $preview->assertOk()->assertJsonPath('data.total_leads', 1);
    }

    public function test_manual_bulk_assignment_preview_and_confirm(): void
    {
        $this->actingAsAdmin();
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $employee = $this->createEmployee('Bulk Manual Exec', $city->city_id);
        $lead = $this->createLead($city->city_id, $state->state_id, 'manual');

        $preview = $this->postJson('/lead-assignments/bulk', [
            'ca_ids' => [$lead->ca_id],
            'employee_ids' => [$employee->employee_id],
            'assignment_mode' => 'manual',
            'preview' => true,
        ]);
        $preview->assertOk()
            ->assertJsonPath('data.preview', true)
            ->assertJsonPath('data.assignments.0.employee_id', $employee->employee_id)
            ->assertJsonPath('data.assignments.0.previous_employee_name', 'Unassigned');

        $this->assertDatabaseMissing('lead_assignment_engines', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'status' => 'Active',
        ]);

        $confirm = $this->postJson('/lead-assignments/bulk', [
            'ca_ids' => [$lead->ca_id],
            'employee_ids' => [$employee->employee_id],
            'assignment_mode' => 'manual',
            'preview' => false,
        ]);
        $confirm->assertOk();

        $this->assertDatabaseHas('lead_assignment_engines', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'status' => 'Active',
        ]);
        $this->assertDatabaseHas('assignment_histories', [
            'ca_id' => $lead->ca_id,
            'new_employee_id' => $employee->employee_id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'Bulk Assignment',
            'module_name' => 'LEAD_ASSIGNMENT_ENGINE',
        ]);
    }

    public function test_round_robin_distributes_leads_equally(): void
    {
        $this->actingAsAdmin();
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $employees = [
            $this->createEmployee('RR One', $city->city_id),
            $this->createEmployee('RR Two', $city->city_id),
            $this->createEmployee('RR Three', $city->city_id),
        ];
        $leads = [];
        for ($i = 0; $i < 6; $i++) {
            $leads[] = $this->createLead($city->city_id, $state->state_id, 'rr'.$i);
        }

        $response = $this->postJson('/lead-assignments/bulk', [
            'ca_ids' => array_map(fn ($l) => $l->ca_id, $leads),
            'employee_ids' => array_map(fn ($e) => $e->employee_id, $employees),
            'assignment_mode' => 'round_robin',
            'preview' => true,
        ])->assertOk();

        $counts = [];
        foreach ($response->json('data.assignments') as $row) {
            $counts[$row['employee_id']] = ($counts[$row['employee_id']] ?? 0) + 1;
        }
        $this->assertSame([2, 2, 2], array_values($counts));
    }

    public function test_round_robin_confirm_assigns_equally_between_two_employees(): void
    {
        $this->actingAsAdmin();
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $employeeA = $this->createEmployee('RR Confirm A', $city->city_id);
        $employeeB = $this->createEmployee('RR Confirm B', $city->city_id);
        $leads = [];
        for ($i = 0; $i < 10; $i++) {
            $leads[] = $this->createLead($city->city_id, $state->state_id, 'rrc'.$i);
        }

        $this->postJson('/lead-assignments/bulk', [
            'ca_ids' => array_map(fn ($l) => $l->ca_id, $leads),
            'employee_ids' => [$employeeA->employee_id, $employeeB->employee_id],
            'assignment_mode' => 'round_robin',
            'preview' => false,
        ])->assertOk();

        $countA = LeadAssignmentEngine::query()
            ->where('employee_id', $employeeA->employee_id)
            ->where('status', 'Active')
            ->count();
        $countB = LeadAssignmentEngine::query()
            ->where('employee_id', $employeeB->employee_id)
            ->where('status', 'Active')
            ->count();

        $this->assertSame(5, $countA);
        $this->assertSame(5, $countB);
        $this->assertSame(10, AssignmentHistory::query()->whereIn('ca_id', array_map(fn ($l) => $l->ca_id, $leads))->count());
    }

    public function test_bulk_lead_ids_endpoint_returns_filtered_ids(): void
    {
        $this->actingAsAdmin();
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $lead = $this->createLead($city->city_id, $state->state_id, 'ids');

        $response = $this->getJson('/lead-assignments/bulk/leads/ids?assignment=unassigned&lead_status=Hot');
        $response->assertOk()
            ->assertJsonStructure(['success', 'data' => ['ca_ids', 'total', 'returned', 'truncated']]);

        $this->assertContains($lead->ca_id, $response->json('data.ca_ids'));
    }

    public function test_workload_balance_prefers_lowest_load(): void
    {
        $this->actingAsAdmin();
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $heavy = $this->createEmployee('Heavy Load', $city->city_id);
        $light = $this->createEmployee('Light Load', $city->city_id);

        for ($i = 0; $i < 3; $i++) {
            $existingLead = $this->createLead($city->city_id, $state->state_id, 'wl'.$i);
            LeadAssignmentEngine::create([
                'ca_id' => $existingLead->ca_id,
                'employee_id' => $heavy->employee_id,
                'assigned_date' => now()->toDateString(),
                'assignment_type' => 'Manual',
                'status' => 'Active',
            ]);
        }

        $newLead = $this->createLead($city->city_id, $state->state_id, 'wl-new');

        $response = $this->postJson('/lead-assignments/bulk', [
            'ca_ids' => [$newLead->ca_id],
            'employee_ids' => [$heavy->employee_id, $light->employee_id],
            'assignment_mode' => 'workload_balance',
            'preview' => true,
        ])->assertOk();

        $this->assertSame($light->employee_id, $response->json('data.assignments.0.employee_id'));
    }

    public function test_city_match_fails_when_no_matching_employee(): void
    {
        $this->actingAsAdmin();
        $states = State::query()->limit(2)->get();
        $this->assertGreaterThanOrEqual(2, $states->count());
        $cityA = City::query()->where('state_id', $states[0]->state_id)->firstOrFail();
        $cityB = City::query()->where('state_id', $states[1]->state_id)->firstOrFail();
        $employee = $this->createEmployee('City B Exec', $cityB->city_id);
        $lead = $this->createLead($cityA->city_id, $states[0]->state_id, 'city-fail');

        $response = $this->postJson('/lead-assignments/bulk', [
            'ca_ids' => [$lead->ca_id],
            'employee_ids' => [$employee->employee_id],
            'assignment_mode' => 'city_match',
            'preview' => true,
        ])->assertOk();

        $this->assertSame('failed', $response->json('data.assignments.0.status'));
        $this->assertSame(1, $response->json('data.failed_rows'));
    }

    public function test_state_match_assigns_employee_in_same_state(): void
    {
        $this->actingAsAdmin();
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $employee = $this->createEmployee('State Match Exec', $city->city_id);
        $lead = $this->createLead($city->city_id, $state->state_id, 'state-ok');

        $response = $this->postJson('/lead-assignments/bulk', [
            'ca_ids' => [$lead->ca_id],
            'employee_ids' => [$employee->employee_id],
            'assignment_mode' => 'state_match',
            'preview' => true,
        ])->assertOk();

        $this->assertSame($employee->employee_id, $response->json('data.assignments.0.employee_id'));
        $this->assertSame('preview', $response->json('data.assignments.0.status'));
    }

    public function test_duplicate_assignment_is_detected_in_preview(): void
    {
        $this->actingAsAdmin();
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $employee = $this->createEmployee('Dup Exec', $city->city_id);
        $lead = $this->createLead($city->city_id, $state->state_id, 'dup');
        LeadAssignmentEngine::create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'assigned_date' => now()->toDateString(),
            'assignment_type' => 'Manual',
            'status' => 'Active',
        ]);

        $response = $this->postJson('/lead-assignments/bulk', [
            'ca_ids' => [$lead->ca_id],
            'employee_ids' => [$employee->employee_id],
            'assignment_mode' => 'manual',
            'preview' => true,
        ])->assertOk();

        $this->assertSame('duplicate', $response->json('data.assignments.0.status'));
    }

    public function test_inactive_employee_rejected(): void
    {
        $this->actingAsAdmin();
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $employee = $this->createEmployee('Inactive Exec', $city->city_id, 'Inactive');
        $lead = $this->createLead($city->city_id, $state->state_id, 'inactive');

        $this->postJson('/lead-assignments/bulk', [
            'ca_ids' => [$lead->ca_id],
            'employee_ids' => [$employee->employee_id],
            'assignment_mode' => 'manual',
            'preview' => true,
        ])->assertStatus(422);
    }

    public function test_employee_role_cannot_bulk_assign(): void
    {
        $employeeUser = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employeeUser);

        $this->postJson('/lead-assignments/bulk', [
            'ca_ids' => [1],
            'employee_ids' => [1],
            'assignment_mode' => 'manual',
            'preview' => true,
        ])->assertForbidden();
    }

    public function test_assignment_history_records_mode_and_ip(): void
    {
        $this->actingAsAdmin();
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $employee = $this->createEmployee('Audit Exec', $city->city_id);
        $lead = $this->createLead($city->city_id, $state->state_id, 'audit');

        $this->postJson('/lead-assignments/bulk', [
            'ca_ids' => [$lead->ca_id],
            'employee_ids' => [$employee->employee_id],
            'assignment_mode' => 'manual',
            'reason' => 'MANUAL_ASSIGN',
            'preview' => false,
        ])->assertOk();

        $history = AssignmentHistory::query()
            ->where('ca_id', $lead->ca_id)
            ->latest('id')
            ->first();

        $this->assertNotNull($history);
        $this->assertSame('manual', $history->assignment_mode);
        $this->assertNotNull($history->ip_address);
    }
}
