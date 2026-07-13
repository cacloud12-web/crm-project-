<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\Employee;
use App\Models\LeadAssignmentEngine;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class EmployeeDashboardTest extends TestCase
{
    use DatabaseTransactions;

    private function employeeUser(): User
    {
        return User::query()->where('email', 'employee@ca.local')->firstOrFail();
    }

    public function test_employee_can_load_employee_dashboard(): void
    {
        $this->actingAs($this->employeeUser());

        $response = $this->getJson('/dashboard/employee');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'employee_id',
                    'welcome' => ['name', 'designation', 'working_status'],
                    'summary' => ['my_leads', 'my_followups', 'hot_leads'],
                    'today_work',
                    'assigned_leads',
                    'followups',
                    'calendar',
                    'recent_activity',
                ],
            ]);

        $employee = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $this->assertSame((int) $employee->employee_id, (int) $response->json('data.employee_id'));
    }

    public function test_employee_cannot_load_admin_dashboard_metrics(): void
    {
        $this->actingAs($this->employeeUser());

        $this->getJson('/dashboard/metrics')->assertForbidden();
    }

    public function test_admin_cannot_load_employee_dashboard_endpoint(): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $this->getJson('/dashboard/employee')->assertForbidden();
    }

    public function test_employee_cannot_access_admin_spa_routes(): void
    {
        $this->actingAs($this->employeeUser());

        $this->get('/ca-master')->assertForbidden();
        $this->get('/reports')->assertForbidden();
        $this->get('/settings')->assertForbidden();
    }

    public function test_employee_cannot_change_own_password(): void
    {
        $this->actingAs($this->employeeUser());

        $this->postJson('/auth/change-password', [
            'current_password' => 'password',
            'password' => 'NewEmployeePass123',
            'password_confirmation' => 'NewEmployeePass123',
        ])->assertForbidden();
    }

    public function test_employee_dashboard_activity_is_self_scoped(): void
    {
        $user = $this->employeeUser();
        $this->actingAs($user);

        $response = $this->getJson('/dashboard/employee');
        $response->assertOk();

        foreach ($response->json('data.recent_activity') ?? [] as $activity) {
            $performer = strtolower((string) ($activity['performed_by'] ?? ''));
            $this->assertTrue(
                str_contains($performer, strtolower($user->name))
                || str_contains($performer, strtolower($user->email)),
                'Activity feed must only include the logged-in employee activity.',
            );
        }
    }

    public function test_employee_dashboard_reflects_assigned_lead_count(): void
    {
        $employee = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $user = $this->employeeUser();

        $unassigned = CaMaster::query()
            ->whereDoesntHave('leadAssignments', fn ($q) => $q->where('status', 'Active'))
            ->limit(3)
            ->pluck('ca_id')
            ->all();

        if (count($unassigned) < 2) {
            $this->markTestSkipped('Not enough unassigned leads in database.');
        }

        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);
        $this->postJson('/lead-assignments/bulk', [
            'ca_ids' => array_slice($unassigned, 0, 2),
            'employee_ids' => [$employee->employee_id],
            'assignment_mode' => 'manual',
            'preview' => false,
        ])->assertOk();

        $expected = CaMaster::query()
            ->countableInStatistics()
            ->whereHas('leadAssignments', fn ($q) => $q
                ->where('employee_id', $employee->employee_id)
                ->where('status', 'Active'))
            ->count();

        $this->actingAs($user);
        app(\App\Services\Cache\CrmCacheService::class)->forgetEmployeeDashboard((int) $employee->employee_id);
        $response = $this->getJson('/dashboard/employee')->assertOk();
        $this->assertSame($expected, (int) $response->json('data.summary.my_leads'));
    }

    public function test_employee_leads_listing_only_shows_assigned_leads(): void
    {
        $user = $this->employeeUser();
        $this->actingAs($user);

        $response = $this->getJson('/ca-masters?all=1')->assertOk();
        $items = $response->json('data.items') ?? $response->json('data') ?? [];
        if (isset($items['data'])) {
            $items = $items['data'];
        }

        $employee = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $allowed = LeadAssignmentEngine::query()
            ->where('employee_id', $employee->employee_id)
            ->where('status', 'Active')
            ->pluck('ca_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($items as $item) {
            $caId = (int) ($item['ca_id'] ?? $item['id'] ?? 0);
            $this->assertContains($caId, $allowed, 'Employee must only see assigned leads.');
        }
    }
}
