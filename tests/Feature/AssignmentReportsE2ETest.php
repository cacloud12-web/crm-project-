<?php

namespace Tests\Feature;

use App\Models\AssignmentHistory;
use App\Models\CaMaster;
use App\Models\City;
use App\Models\DailyEmployeeTarget;
use App\Models\DemoProvider;
use App\Models\DemoSchedule;
use App\Models\Employee;
use App\Models\LeadAssignmentEngine;
use App\Models\State;
use App\Models\User;
use App\Models\YearlyEmployeeTarget;
use App\Services\Dashboard\DashboardService;
use App\Services\Reports\ReportsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * End-to-end assignment → activity → targets → reports workflow.
 */
class AssignmentReportsE2ETest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::query()->where('email', 'admin@ca.local')->firstOrFail();
    }

    private function manager(): User
    {
        return User::query()->where('email', 'manager@ca.local')->firstOrFail();
    }

    private function employeeUser(): User
    {
        return User::query()->where('email', 'employee@ca.local')->firstOrFail();
    }

    private function seededEmployee(): Employee
    {
        return Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
    }

    private function createEmployee(string $label): Employee
    {
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $suffix = str_replace(' ', '.', strtolower($label)).random_int(100, 999);

        return Employee::query()->create([
            'name' => $label,
            'email_id' => $suffix.'@e2e.test',
            'mobile_no' => '8'.substr(str_pad((string) random_int(100000000, 999999999), 9, '0'), -9),
            'role' => 'Sales Executive',
            'city_id' => $city->city_id,
            'status' => 'Active',
            'date_of_joining' => now()->toDateString(),
        ]);
    }

    private function createLead(string $label): CaMaster
    {
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $suffix = str_replace(' ', '.', strtolower($label)).random_int(1000, 9999);

        return CaMaster::query()->create([
            'firm_name' => 'E2E Firm '.$label,
            'ca_name' => 'E2E CA '.$label,
            'mobile_no' => '9'.substr(str_pad((string) random_int(100000000, 999999999), 9, '0'), -9),
            'email_id' => $suffix.'@e2e.test',
            'city_id' => $city->city_id,
            'state_id' => $state->state_id,
            'status' => 'New',
            'rating' => 4,
            'team_size' => 5,
        ]);
    }

    public function test_full_assignment_workflow_with_rbac_and_history(): void
    {
        $admin = $this->admin();
        $employee = $this->seededEmployee();
        $otherEmployee = $this->createEmployee('E2E Other Exec');
        $lead = $this->createLead('Workflow');

        $this->actingAs($admin);
        $assign = $this->postJson('/lead-assignments', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'assignment_type' => 'Manual',
            'reason' => 'E2E_MANUAL',
        ]);
        $assign->assertCreated();

        $assignmentId = $assign->json('data.assignment_id');
        $this->assertNotNull($assignmentId);

        $this->assertDatabaseHas('lead_assignment_engines', [
            'assignment_id' => $assignmentId,
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'status' => 'Active',
        ]);
        $this->assertDatabaseHas('assignment_histories', [
            'ca_id' => $lead->ca_id,
            'new_employee_id' => $employee->employee_id,
        ]);

        $duplicate = $this->postJson('/lead-assignments', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'assignment_type' => 'Manual',
            'reason' => 'E2E_DUP',
        ]);
        $duplicate->assertStatus(422);

        $this->assertSame(1, LeadAssignmentEngine::query()
            ->where('ca_id', $lead->ca_id)
            ->where('status', 'Active')
            ->count());

        $this->actingAs($this->employeeUser());
        $this->getJson('/ca-masters/'.$lead->ca_id)->assertOk();

        $otherUser = User::query()->create([
            'name' => $otherEmployee->name,
            'email' => $otherEmployee->email_id,
            'password' => bcrypt('password'),
            'crm_role' => 'employee',
            'is_active' => true,
        ]);
        $otherEmployee->update(['user_id' => $otherUser->id]);

        $this->actingAs($otherUser);
        $this->getJson('/ca-masters/'.$lead->ca_id)->assertForbidden();

        $this->actingAs($admin);
        $this->putJson('/lead-assignments/'.$assignmentId, [
            'ca_id' => $lead->ca_id,
            'employee_id' => $otherEmployee->employee_id,
            'assignment_type' => 'Manual',
            'reason' => 'E2E_REASSIGN',
        ])->assertOk();

        $this->assertDatabaseHas('lead_assignment_engines', [
            'assignment_id' => $assignmentId,
            'employee_id' => $otherEmployee->employee_id,
            'status' => 'Active',
        ]);
        $this->assertGreaterThanOrEqual(2, AssignmentHistory::query()->where('ca_id', $lead->ca_id)->count());

        $history = $this->getJson('/assignment-histories?ca_id='.$lead->ca_id.'&per_page=10');
        $history->assertOk();
        $items = $history->json('data.items') ?? [];
        $this->assertNotEmpty($items);
    }

    public function test_assignment_dashboard_endpoints_load_for_admin(): void
    {
        $this->actingAs($this->admin());

        $this->getJson('/lead-assignments?per_page=5')
            ->assertOk()
            ->assertJsonStructure(['success', 'data' => ['items', 'pagination']]);

        $this->getJson('/assignment-dashboard/capacity')
            ->assertOk()
            ->assertJsonStructure(['success', 'data']);

        $this->getJson('/assignment-dashboard/heat-map')
            ->assertOk()
            ->assertJsonStructure(['success', 'data']);
    }

    public function test_daily_and_yearly_targets_rbac_and_progress(): void
    {
        $admin = $this->admin();
        $employee = $this->seededEmployee();
        $today = now()->toDateString();
        $year = (int) now()->format('Y');

        $this->actingAs($admin);
        $this->postJson('/daily-employee-targets', [
            'employee_id' => $employee->employee_id,
            'target_date' => $today,
            'lead_target' => 30,
            'call_target' => 20,
            'demo_target' => 4,
            'followup_target' => 10,
        ])->assertCreated();

        $this->postJson('/yearly-employee-targets', [
            'employee_id' => $employee->employee_id,
            'target_year' => $year,
            'lead_target' => 500,
            'call_target' => 300,
            'demo_target' => 60,
            'followup_target' => 200,
        ])->assertCreated();

        $this->actingAs($this->employeeUser());
        $this->postJson('/daily-employee-targets', [
            'employee_id' => $employee->employee_id,
            'target_date' => $today,
            'lead_target' => 99,
        ])->assertForbidden();

        $this->postJson('/yearly-employee-targets', [
            'employee_id' => $employee->employee_id,
            'target_year' => $year,
            'lead_target' => 999,
        ])->assertForbidden();

        $this->actingAs($admin);
        $this->getJson('/yearly-employee-targets/summary?year='.$year)
            ->assertOk()
            ->assertJsonStructure(['success', 'data']);

        $this->assertTrue(
            DailyEmployeeTarget::query()
                ->where('employee_id', $employee->employee_id)
                ->whereDate('target_date', $today)
                ->exists()
        );
        $this->assertDatabaseHas('yearly_employee_targets', [
            'employee_id' => $employee->employee_id,
            'target_year' => $year,
        ]);
    }

    public function test_demo_calendar_integrates_with_assignment_and_blocks_conflicts(): void
    {
        $admin = $this->admin();
        $employee = $this->seededEmployee();
        $lead = $this->createLead('Demo Cal');
        $provider = DemoProvider::query()->where('is_active', true)->orderBy('sort_order')->firstOrFail();

        LeadAssignmentEngine::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'assigned_date' => now()->toDateString(),
            'assignment_type' => 'Manual',
            'status' => 'Active',
        ]);

        $this->actingAs($admin);
        $monday = Carbon::now()->next('Monday');
        $demoAt = $monday->copy()->setTime(11, 0);

        $this->postJson('/demo-calendar/schedule', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'demo_provider_id' => $provider->id,
            'demo_at' => $demoAt->toDateTimeString(),
            'demo_end_at' => $demoAt->copy()->addHour()->toDateTimeString(),
            'meeting_link' => $provider->default_meeting_link ?: 'https://meet.example/demo',
        ])->assertOk();

        $this->assertDatabaseHas('demo_schedules', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'demo_provider_id' => $provider->id,
        ]);

        $events = $this->getJson('/demo-calendar/events?view=week&date='.$monday->toDateString())
            ->assertOk()
            ->json('data');
        $this->assertNotEmpty($events);

        $leadTwo = $this->createLead('Demo Conflict');
        $conflict = $this->postJson('/demo-calendar/check-conflict', [
            'ca_id' => $leadTwo->ca_id,
            'demo_provider_id' => $provider->id,
            'demo_at' => $demoAt->toDateTimeString(),
            'demo_end_at' => $demoAt->copy()->addHour()->toDateTimeString(),
        ])->assertOk()->json('data');

        $this->assertFalse($conflict['available']);
    }

    public function test_report_totals_match_database_for_assignment_statistics(): void
    {
        $admin = $this->admin();
        $employee = $this->seededEmployee();
        $lead = $this->createLead('Report Match');

        $this->actingAs($admin);
        $this->postJson('/lead-assignments', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'assignment_type' => 'Manual',
            'reason' => 'E2E_REPORT',
        ])->assertCreated();

        $dbCount = AssignmentHistory::query()
            ->where('ca_id', $lead->ca_id)
            ->count();

        $report = app(ReportsService::class)->report('assignment_statistics', [
            'from' => now()->subDays(7)->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ]);

        $this->assertArrayHasKey('rows', $report);
        $this->assertArrayHasKey('summary', $report);
        $this->assertGreaterThanOrEqual(1, (int) ($report['summary']['total_assignments'] ?? 0));
        $this->assertGreaterThanOrEqual($dbCount, (int) ($report['summary']['total_assignments'] ?? 0));
    }

    public function test_all_report_slugs_return_db_backed_rows_and_export(): void
    {
        $this->actingAs($this->admin());

        $slugs = array_keys(config('reports.reports', []));
        $filters = [
            'from' => now()->subDays(30)->toDateString(),
            'to' => now()->toDateString(),
        ];

        foreach ($slugs as $slug) {
            $response = $this->getJson('/reports/'.$slug.'?'.http_build_query($filters));
            $response->assertOk()
                ->assertJsonPath('data.slug', $slug)
                ->assertJsonStructure(['data' => ['slug', 'columns', 'rows', 'summary']]);

            $export = $this->get('/reports/'.$slug.'/export?format=csv&'.http_build_query($filters), [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ]);
            $export->assertOk();

            $pdf = $this->get('/reports/'.$slug.'/export?format=pdf&'.http_build_query($filters), [
                'Accept' => 'application/pdf, application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ]);
            $pdf->assertOk();
            $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('content-type'));
        }
    }

    public function test_report_date_presets_and_employee_filter(): void
    {
        $this->actingAs($this->admin());
        $employee = $this->seededEmployee();

        $presets = ['today', 'yesterday', 'this_week', 'this_month'];
        foreach ($presets as $preset) {
            $this->getJson('/reports/lead_conversion?preset='.$preset)
                ->assertOk()
                ->assertJsonPath('data.slug', 'lead_conversion');
        }

        $this->getJson('/reports/employee_performance?employee_id='.$employee->employee_id)
            ->assertOk()
            ->assertJsonPath('data.slug', 'employee_performance');
    }

    public function test_employee_and_manager_report_access_restrictions(): void
    {
        $this->actingAs($this->employeeUser());
        $this->getJson('/reports')->assertForbidden();
        $this->getJson('/reports/lead_conversion')->assertForbidden();

        $this->actingAs($this->manager());
        $this->getJson('/reports')->assertOk();
        $this->getJson('/reports/assignment_statistics')->assertOk();
    }

    public function test_dashboard_metrics_match_database_after_assignment(): void
    {
        $admin = $this->admin();
        $employee = $this->seededEmployee();
        $lead = $this->createLead('Dash Metrics');

        $this->actingAs($admin);
        $this->postJson('/lead-assignments', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'assignment_type' => 'Manual',
            'reason' => 'E2E_DASH',
        ])->assertCreated();

        $this->flushCrmCachesForTesting();
        $metrics = app(DashboardService::class)->metrics();
        $today = now()->toDateString();
        $dbTotal = CaMaster::query()
            ->countableInStatistics()
            ->whereDate('created_at', $today)
            ->count();

        $this->assertSame($dbTotal, (int) $metrics['total_leads']);
        $this->assertGreaterThanOrEqual(1, (int) ($metrics['new_status_leads'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($metrics['assigned_leads'] ?? 0));
    }

    public function test_unassignment_via_status_update(): void
    {
        $admin = $this->admin();
        $employee = $this->seededEmployee();
        $lead = $this->createLead('Unassign');

        $this->actingAs($admin);
        $created = $this->postJson('/lead-assignments', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'assignment_type' => 'Manual',
            'reason' => 'E2E_UNASSIGN',
        ])->assertCreated();

        $assignmentId = $created->json('data.assignment_id');

        $this->patchJson('/lead-assignments/'.$assignmentId.'/status', [
            'status' => 'Paused',
        ])->assertOk();

        $this->assertDatabaseHas('lead_assignment_engines', [
            'assignment_id' => $assignmentId,
            'status' => 'Paused',
        ]);
    }
}
