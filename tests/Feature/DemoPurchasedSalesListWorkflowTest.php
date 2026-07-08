<?php

namespace Tests\Feature;

use App\Models\AssignmentHistory;
use App\Models\CaMaster;
use App\Models\City;
use App\Models\DemoSchedule;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\LeadAssignmentEngine;
use App\Models\SalesListEntry;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DemoPurchasedSalesListWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    private function createLead(): CaMaster
    {
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $suffix = (string) random_int(1000, 9999);

        return CaMaster::query()->create([
            'firm_name' => 'E2E Firm '.$suffix,
            'ca_name' => 'E2E CA '.$suffix,
            'mobile_no' => '9'.substr(str_pad((string) random_int(100000000, 999999999), 9, '0'), -9),
            'email_id' => 'e2e.'.$suffix.'@test.local',
            'city_id' => $city->city_id,
            'state_id' => $state->state_id,
            'status' => 'Hot',
        ]);
    }

    public function test_follow_up_demo_scheduled_creates_demo_schedule(): void
    {
        $employeeUser = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $employee = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $lead = $this->createLead();

        LeadAssignmentEngine::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'assigned_date' => now()->toDateString(),
            'assignment_type' => 'Manual',
            'status' => 'Active',
        ]);

        $this->actingAs($employeeUser);

        $response = $this->postJson('/follow-ups', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'followup_type' => 'Demo Scheduled',
            'scheduled_date' => now()->addDay()->setTime(11, 0)->toDateTimeString(),
            'remarks' => 'Product walkthrough',
            'team_size' => 8,
            'meeting_link' => 'https://meet.google.com/awm-gsft-xov',
        ]);

        $response->assertCreated();
        $followupId = $response->json('data.followup_id');
        $this->assertNotNull($followupId);

        $this->assertDatabaseHas('demo_schedules', [
            'ca_id' => $lead->ca_id,
            'followup_id' => $followupId,
            'status' => 'scheduled',
        ]);

        $this->assertDatabaseHas('follow_ups', [
            'followup_id' => $followupId,
            'team_size' => 8,
            'meeting_link' => 'https://meet.google.com/awm-gsft-xov',
        ]);
    }

    public function test_purchased_demo_creates_sales_list_without_duplicates(): void
    {
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $manager = Employee::query()->create([
            'name' => 'Workflow Manager',
            'email_id' => 'wf.manager.'.random_int(100, 999).'@test.local',
            'mobile_no' => '7000000001',
            'role' => 'Manager',
            'city_id' => $city->city_id,
            'status' => 'Active',
            'date_of_joining' => now()->toDateString(),
        ]);
        $employeeUser = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $employee = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $lead = $this->createLead();

        LeadAssignmentEngine::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'assigned_date' => now()->toDateString(),
            'assignment_type' => 'Manual',
            'status' => 'Active',
        ]);

        AssignmentHistory::query()->create([
            'ca_id' => $lead->ca_id,
            'new_employee_id' => $employee->employee_id,
            'assignment_type' => 'Manual',
            'assigned_by' => $manager->employee_id,
            'assigned_at' => now(),
        ]);

        $schedule = DemoSchedule::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'demo_at' => now()->subHour(),
            'meeting_link' => 'https://meet.example.com/done',
            'status' => DemoSchedule::STATUS_SCHEDULED,
            'customer_name' => $lead->ca_name,
            'firm_name' => $lead->firm_name,
        ]);

        $this->actingAs($employeeUser);

        $payload = [
            'result' => 'Purchased',
            'purchase_date' => now()->toDateString(),
            'plan_purchased' => 'CRM Annual',
            'customer_name' => $lead->ca_name,
            'firm_name' => $lead->firm_name,
            'mobile_no' => $lead->mobile_no,
            'total_amount' => 25000,
            'amount_received' => 10000,
            'notes' => 'Closed on call',
        ];

        $this->postJson('/workflow/demos/'.$schedule->id.'/result', $payload)
            ->assertOk();

        $this->assertDatabaseHas('sales_list_entries', [
            'ca_id' => $lead->ca_id,
            'firm_name' => $lead->firm_name,
            'plan_purchased' => 'CRM Annual',
            'payment_status' => 'Partial',
            'manager_id' => $manager->employee_id,
        ]);

        $entry = SalesListEntry::query()->where('ca_id', $lead->ca_id)->firstOrFail();
        $this->assertSame(15000.0, (float) $entry->balance_amount);

        $this->actingAs($employeeUser);
        $this->getJson('/sales-list')->assertForbidden();

        $managerUser = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $this->actingAs($managerUser);
        $this->getJson('/sales-list')
            ->assertOk()
            ->assertJsonFragment(['firm_name' => $lead->firm_name]);

        $this->actingAs($employeeUser);
        $this->postJson('/workflow/demos/'.$schedule->id.'/result', $payload)
            ->assertStatus(422);

        $this->assertSame(1, SalesListEntry::query()->where('ca_id', $lead->ca_id)->count());
    }

    public function test_manager_can_edit_sales_list_after_purchase(): void
    {
        $managerUser = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $employee = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $lead = $this->createLead();

        $schedule = DemoSchedule::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'demo_at' => now()->subHour(),
            'meeting_link' => 'https://meet.example.com/edit',
            'status' => DemoSchedule::STATUS_SCHEDULED,
            'customer_name' => $lead->ca_name,
            'firm_name' => $lead->firm_name,
        ]);

        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);
        $this->postJson('/workflow/demos/'.$schedule->id.'/result', [
            'result' => 'Purchased',
            'purchase_date' => now()->toDateString(),
            'plan_purchased' => 'CRM Annual',
            'customer_name' => $lead->ca_name,
            'firm_name' => $lead->firm_name,
            'total_amount' => 25000,
            'amount_received' => 0,
        ])->assertOk();

        $entry = SalesListEntry::query()->where('ca_id', $lead->ca_id)->firstOrFail();

        $this->actingAs($managerUser);
        $this->patchJson('/sales-list/'.$entry->id, [
            'customer_name' => 'Updated Customer',
            'firm_name' => $entry->firm_name,
            'plan_purchased' => 'CRM Annual',
            'purchase_date' => $entry->purchase_date->toDateString(),
            'total_amount' => 25000,
            'amount_received' => 25000,
            'invoice_number' => $entry->invoice_number,
        ])->assertOk()
            ->assertJsonPath('data.customer_name', 'Updated Customer')
            ->assertJsonPath('data.payment_status', 'Paid');
    }
}
