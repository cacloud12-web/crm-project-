<?php

namespace Tests\Feature;

use App\Models\CallLog;
use App\Models\CaMaster;
use App\Models\DailyEmployeeTarget;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DailyEmployeeTargetTest extends TestCase
{
    use DatabaseTransactions;

    private function adminUser(): User
    {
        return User::query()->where('email', 'admin@ca.local')->firstOrFail();
    }

    private function managerUser(): User
    {
        return User::query()->where('email', 'manager@ca.local')->firstOrFail();
    }

    private function employeeUser(): User
    {
        return User::query()->where('email', 'employee@ca.local')->firstOrFail();
    }

    private function employee(): Employee
    {
        return Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
    }

    public function test_admin_can_assign_daily_target_to_employee(): void
    {
        $employee = $this->employee();
        $this->actingAs($this->adminUser());

        $response = $this->postJson('/daily-employee-targets', [
            'employee_id' => $employee->employee_id,
            'target_date' => now()->toDateString(),
            'lead_target' => 40,
            'call_target' => 25,
            'demo_target' => 5,
            'followup_target' => 15,
            'notes' => 'Focus on hot leads first.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.lead_target', 40)
            ->assertJsonPath('data.call_target', 25);

        $this->assertDatabaseHas('daily_employee_targets', [
            'employee_id' => $employee->employee_id,
            'lead_target' => 40,
            'call_target' => 25,
        ]);
    }

    public function test_employee_sees_target_on_dashboard(): void
    {
        $employee = $this->employee();
        $admin = $this->adminUser();

        $this->actingAs($admin)->postJson('/daily-employee-targets', [
            'employee_id' => $employee->employee_id,
            'target_date' => now()->toDateString(),
            'lead_target' => 40,
            'call_target' => 25,
            'demo_target' => 5,
            'followup_target' => 15,
        ])->assertCreated();

        $this->actingAs($this->employeeUser());

        $response = $this->getJson('/dashboard/employee')->assertOk();

        $response->assertJsonPath('data.daily_target.has_target', true)
            ->assertJsonPath('data.daily_target.target.lead_target', 40);
    }

    public function test_employee_cannot_create_or_update_daily_target(): void
    {
        $employee = $this->employee();

        $this->actingAs($this->employeeUser());

        $this->postJson('/daily-employee-targets', [
            'employee_id' => $employee->employee_id,
            'target_date' => now()->toDateString(),
            'lead_target' => 10,
        ])->assertForbidden();

        $target = DailyEmployeeTarget::query()->create([
            'employee_id' => $employee->employee_id,
            'target_date' => now()->toDateString(),
            'lead_target' => 10,
            'created_by' => $this->adminUser()->id,
            'updated_by' => $this->adminUser()->id,
        ]);

        $this->putJson('/daily-employee-targets/'.$target->id, [
            'lead_target' => 99,
        ])->assertForbidden();
    }

    public function test_call_log_increases_completed_calls_in_target_progress(): void
    {
        $employee = $this->employee();
        $admin = $this->adminUser();
        $today = now()->toDateString();

        $this->actingAs($admin)->postJson('/daily-employee-targets', [
            'employee_id' => $employee->employee_id,
            'target_date' => $today,
            'call_target' => 25,
        ])->assertCreated();

        CallLog::query()->create([
            'ca_id' => CaMaster::query()->countableInStatistics()->value('ca_id'),
            'employee_id' => $employee->employee_id,
            'called_at' => now(),
            'call_status' => 'Connected',
            'call_note' => 'Test call',
            'created_by_user_id' => $this->employeeUser()->id,
        ]);

        $this->actingAs($admin);
        $response = $this->getJson('/daily-employee-targets?preset=today&employee_id='.$employee->employee_id);

        $response->assertOk();
        $items = $response->json('data.items') ?? [];
        $row = collect($items)->first(fn ($item) => (int) ($item['employee_id'] ?? 0) === (int) $employee->employee_id);
        $this->assertNotNull($row);
        $callMetric = collect($row['metrics'] ?? [])->firstWhere('key', 'call');
        $this->assertSame(1, (int) ($callMetric['completed'] ?? 0));
    }

    public function test_duplicate_target_for_same_employee_and_date_is_rejected(): void
    {
        $employee = $this->employee();
        $this->actingAs($this->adminUser());

        $payload = [
            'employee_id' => $employee->employee_id,
            'target_date' => now()->toDateString(),
            'lead_target' => 20,
        ];

        $this->postJson('/daily-employee-targets', $payload)->assertCreated();
        $this->postJson('/daily-employee-targets', $payload)->assertStatus(409);
    }

    public function test_manager_can_view_team_target_summary(): void
    {
        $this->actingAs($this->managerUser());

        $this->getJson('/daily-employee-targets/summary?preset=today')->assertOk();
    }
}
