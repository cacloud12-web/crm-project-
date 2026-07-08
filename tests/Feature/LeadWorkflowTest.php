<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\City;
use App\Models\DemoReminder;
use App\Models\DemoSchedule;
use App\Models\Employee;
use App\Models\LeadAssignmentEngine;
use App\Models\PurchasedCustomer;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LeadWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsAdmin(): User
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        return $admin;
    }

    private function createLead(): CaMaster
    {
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $suffix = (string) random_int(1000, 9999);

        return CaMaster::query()->create([
            'firm_name' => 'Workflow Firm '.$suffix,
            'ca_name' => 'Workflow CA '.$suffix,
            'mobile_no' => '9'.substr(str_pad((string) random_int(100000000, 999999999), 9, '0'), -9),
            'email_id' => 'wf.'.$suffix.'@test.local',
            'city_id' => $city->city_id,
            'state_id' => $state->state_id,
            'status' => 'Hot',
        ]);
    }

    private function createEmployee(): Employee
    {
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $suffix = (string) random_int(100, 999);

        return Employee::query()->create([
            'name' => 'Workflow Exec '.$suffix,
            'email_id' => 'wf.exec.'.$suffix.'@test.local',
            'mobile_no' => '8'.substr(str_pad((string) random_int(100000000, 999999999), 9, '0'), -9),
            'role' => 'Sales Executive',
            'city_id' => $city->city_id,
            'status' => 'Active',
            'date_of_joining' => now()->toDateString(),
        ]);
    }

    public function test_call_creates_log_history_and_follow_up(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead();
        $employee = $this->createEmployee();

        LeadAssignmentEngine::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'assigned_date' => now()->toDateString(),
            'assignment_type' => 'Manual',
            'status' => 'Active',
        ]);

        $response = $this->postJson('/workflow/calls', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'call_status' => 'Connected',
            'call_note' => 'Spoke with CA',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('call_logs', [
            'ca_id' => $lead->ca_id,
            'call_status' => 'Connected',
        ]);
        $this->assertDatabaseHas('ca_masters', [
            'ca_id' => $lead->ca_id,
            'call_status' => 'Connected',
            'workflow_stage' => 'called',
        ]);
        $this->assertNull($response->json('data.next_follow_up'));
    }

    public function test_connected_call_with_next_date_creates_follow_up(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead();
        $employee = $this->createEmployee();

        LeadAssignmentEngine::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'assigned_date' => now()->toDateString(),
            'assignment_type' => 'Manual',
            'status' => 'Active',
        ]);

        $response = $this->postJson('/workflow/calls', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'call_status' => 'Connected',
            'call_note' => 'Call back tomorrow',
            'next_followup_date' => now()->addDay()->toDateString(),
            'next_followup_time' => '10:00',
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('data.next_follow_up'));
    }

    public function test_demo_schedule_requires_link_and_queues_reminders(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead();
        $employee = $this->createEmployee();

        $response = $this->postJson('/workflow/demos', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'demo_at' => now()->addDay()->setTime(15, 0)->toDateTimeString(),
            'meeting_link' => 'https://meet.example.com/demo-1',
            'notes' => 'Product walkthrough',
        ]);

        $response->assertOk();
        $scheduleId = $response->json('data.demo_schedule.id');
        $this->assertNotNull($scheduleId);

        $this->assertDatabaseHas('demo_schedules', [
            'id' => $scheduleId,
            'meeting_link' => 'https://meet.example.com/demo-1',
            'status' => 'scheduled',
        ]);

        $this->assertTrue(
            DemoReminder::query()->where('demo_schedule_id', $scheduleId)->exists()
        );
    }

    public function test_purchased_demo_result_creates_purchased_customer(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead();
        $employee = $this->createEmployee();

        $schedule = DemoSchedule::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'demo_at' => now()->subHour(),
            'meeting_link' => 'https://meet.example.com/done',
            'status' => DemoSchedule::STATUS_SCHEDULED,
            'customer_name' => $lead->ca_name,
            'firm_name' => $lead->firm_name,
        ]);

        $response = $this->postJson('/workflow/demos/'.$schedule->id.'/result', [
            'result' => 'Purchased',
            'purchase_date' => now()->toDateString(),
            'plan_purchased' => 'CRM Annual',
            'customer_name' => $lead->ca_name,
            'firm_name' => $lead->firm_name,
            'total_amount' => 25000,
            'amount_received' => 0,
            'notes' => 'Closed deal',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('demo_results', [
            'demo_schedule_id' => $schedule->id,
            'result' => 'Purchased',
        ]);
        $this->assertDatabaseHas('purchased_customers', [
            'ca_id' => $lead->ca_id,
            'reference_employee_name' => $employee->name,
            'status' => 'Purchased',
        ]);
        $this->assertDatabaseHas('sales_list_entries', [
            'ca_id' => $lead->ca_id,
            'plan_purchased' => 'CRM Annual',
            'payment_status' => 'Pending',
        ]);
        $this->assertDatabaseHas('ca_masters', [
            'ca_id' => $lead->ca_id,
            'software_purchased' => true,
            'workflow_stage' => 'purchased',
            'status' => 'Purchased',
        ]);
    }

    public function test_employee_cannot_see_purchased_list(): void
    {
        $user = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $employee = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();

        $lead = $this->createLead();
        PurchasedCustomer::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'customer_name' => $lead->ca_name,
            'firm_name' => $lead->firm_name,
            'mobile_no' => $lead->mobile_no,
            'email_id' => $lead->email_id,
            'purchase_date' => now()->toDateString(),
            'reference_employee_name' => $employee->name,
            'status' => 'Purchased',
        ]);

        $this->actingAs($user);
        $response = $this->getJson('/workflow/lists');
        $response->assertOk();
        $this->assertFalse($response->json('data.can_view_purchased'));
        $this->assertSame([], $response->json('data.purchased'));
    }
}
