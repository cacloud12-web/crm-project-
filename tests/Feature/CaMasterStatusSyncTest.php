<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Models\CaMaster;
use App\Models\City;
use App\Models\DemoSchedule;
use App\Models\Employee;
use App\Models\LeadAssignmentEngine;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CaMasterStatusSyncTest extends TestCase
{
    use DatabaseTransactions;

    private function createLead(string $status = 'Hot'): CaMaster
    {
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $suffix = (string) random_int(1000, 9999);

        return CaMaster::query()->create([
            'firm_name' => 'Status Firm '.$suffix,
            'ca_name' => 'Status CA '.$suffix,
            'mobile_no' => '9'.substr(str_pad((string) random_int(100000000, 999999999), 9, '0'), -9),
            'email_id' => 'status.'.$suffix.'@test.local',
            'city_id' => $city->city_id,
            'state_id' => $state->state_id,
            'status' => $status,
        ]);
    }

    public function test_demo_scheduled_follow_up_updates_master_data_status(): void
    {
        $employeeUser = CrmTestAccounts::employeeUser();
        $employee = CrmTestAccounts::employee();
        $lead = $this->createLead();

        LeadAssignmentEngine::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'assigned_date' => now()->toDateString(),
            'assignment_type' => 'Manual',
            'status' => 'Active',
        ]);

        $this->actingAs($employeeUser);

        $this->postJson('/follow-ups', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'followup_type' => 'Demo Scheduled',
            'scheduled_date' => now()->addDay()->setTime(11, 0)->toDateTimeString(),
            'remarks' => 'Product walkthrough',
            'meeting_link' => 'https://meet.example.com/demo-sync',
        ])->assertCreated();

        $this->assertDatabaseHas('ca_masters', [
            'ca_id' => $lead->ca_id,
            'status' => 'Demo Scheduled',
        ]);
    }

    public function test_purchased_demo_result_updates_master_data_status_to_purchased(): void
    {
        $admin = CrmTestAccounts::admin();
        $employee = CrmTestAccounts::employee();
        $lead = $this->createLead();

        $schedule = DemoSchedule::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'demo_at' => now()->subHour(),
            'meeting_link' => 'https://meet.example.com/purchased',
            'status' => DemoSchedule::STATUS_SCHEDULED,
            'customer_name' => $lead->ca_name,
            'firm_name' => $lead->firm_name,
        ]);

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

        $this->assertDatabaseHas('ca_masters', [
            'ca_id' => $lead->ca_id,
            'status' => 'Purchased',
            'software_purchased' => true,
        ]);
    }

    public function test_master_data_status_filter_returns_only_matching_records(): void
    {
        $admin = CrmTestAccounts::admin();
        $interested = $this->createLead('Interested');
        $this->createLead('Demo Scheduled');

        $this->actingAs($admin);

        $response = $this->getJson('/ca-masters?status=Interested&per_page=50');
        $response->assertOk();

        $items = collect($response->json('data.items'));
        $this->assertTrue($items->contains(fn ($row) => (int) ($row['ca_id'] ?? 0) === (int) $interested->ca_id));
        $this->assertTrue($items->every(fn ($row) => ($row['status'] ?? '') === 'Interested'));
    }
}
