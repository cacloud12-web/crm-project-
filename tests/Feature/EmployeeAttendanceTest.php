<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\User;
use App\Services\Rbac\RbacDatabaseService;
use App\Services\Rbac\RbacMatrixService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeeAttendanceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('employee_attendances')) {
            $this->markTestSkipped('employee_attendances table is not migrated yet');
        }

        app(RbacDatabaseService::class)->ensureConfigDefaultGrants();
        app(RbacMatrixService::class)->flushCache();
    }

    private function actingAsAdmin(): User
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        return $admin;
    }

    private function actingAsManager(): User
    {
        $manager = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $this->actingAs($manager);

        return $manager;
    }

    private function actingAsEmployee(): User
    {
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employee);

        return $employee;
    }

    private function scopedTeamEmployee(): Employee
    {
        $employee = Employee::query()
            ->where('status', 'Active')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('role')
                    ->orWhere('role', 'ilike', '%executive%')
                    ->orWhere('role', 'ilike', '%employee%')
                    ->orWhere('role', 'ilike', '%sales%');
            })
            ->first();

        if (! $employee) {
            $this->markTestSkipped('No in-scope team employee available');
        }

        return $employee;
    }

    public function test_admin_dashboard_includes_attendance_summary(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/dashboard/metrics')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'attendance_summary' => ['date', 'total', 'present', 'absent', 'not_marked'],
                ],
            ]);
    }

    public function test_manager_can_view_attendance_summary(): void
    {
        $this->actingAsManager();

        $this->getJson('/attendance/summary')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['date', 'total', 'present', 'absent', 'not_marked'],
            ]);
    }

    public function test_employee_cannot_manage_attendance(): void
    {
        $this->actingAsEmployee();

        $this->getJson('/attendance/summary')->assertForbidden();
        $this->getJson('/attendance')->assertForbidden();
        $this->postJson('/attendance', [
            'employee_id' => 1,
            'status' => 'present',
        ])->assertForbidden();
    }

    public function test_admin_can_mark_and_update_attendance_without_duplicates(): void
    {
        $admin = $this->actingAsAdmin();
        $employee = $this->scopedTeamEmployee();
        $today = now()->toDateString();

        EmployeeAttendance::query()
            ->where('employee_id', $employee->employee_id)
            ->whereDate('attendance_date', $today)
            ->delete();

        $this->postJson('/attendance', [
            'employee_id' => $employee->employee_id,
            'status' => 'present',
            'date' => $today,
        ])->assertOk()
            ->assertJsonPath('data.status', 'present');

        $this->assertTrue(
            EmployeeAttendance::query()
                ->where('employee_id', $employee->employee_id)
                ->whereDate('attendance_date', $today)
                ->where('status', 'present')
                ->where('marked_by', $admin->id)
                ->exists()
        );

        $this->postJson('/attendance', [
            'employee_id' => $employee->employee_id,
            'status' => 'absent',
            'date' => $today,
        ])->assertOk()
            ->assertJsonPath('data.status', 'absent');

        $this->assertSame(
            1,
            EmployeeAttendance::query()
                ->where('employee_id', $employee->employee_id)
                ->whereDate('attendance_date', $today)
                ->count()
        );

        $list = $this->getJson('/attendance?date='.$today)->assertOk();
        $match = collect($list->json('data.items'))->firstWhere('employee_id', $employee->employee_id);
        $this->assertNotNull($match);
        $this->assertSame('absent', $match['status']);

        $summary = $this->getJson('/attendance/summary?date='.$today)->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(1, (int) $summary['absent']);
    }

    public function test_manager_can_mark_team_employee_attendance(): void
    {
        $manager = $this->actingAsManager();
        $employee = $this->scopedTeamEmployee();
        $today = now()->toDateString();

        $this->postJson('/attendance', [
            'employee_id' => $employee->employee_id,
            'status' => 'present',
            'date' => $today,
        ])->assertOk()
            ->assertJsonPath('data.status', 'present');

        $this->assertTrue(
            EmployeeAttendance::query()
                ->where('employee_id', $employee->employee_id)
                ->whereDate('attendance_date', $today)
                ->where('status', 'present')
                ->where('marked_by', $manager->id)
                ->exists()
        );
    }

    public function test_manager_cannot_mark_out_of_scope_employee(): void
    {
        $this->actingAsManager();

        $outside = Employee::query()->create([
            'name' => 'Out Of Scope Attendee',
            'email_id' => 'out.of.scope.att.'.microtime(true).'@test.local',
            'mobile_no' => '9'.substr(str_replace('.', '', (string) microtime(true)), -9),
            'role' => 'Director',
            'status' => 'Active',
            'date_of_joining' => now()->toDateString(),
        ]);

        $this->postJson('/attendance', [
            'employee_id' => $outside->employee_id,
            'status' => 'present',
            'date' => now()->toDateString(),
        ])->assertForbidden();
    }

    public function test_future_attendance_is_rejected(): void
    {
        $this->actingAsAdmin();
        $employee = $this->scopedTeamEmployee();
        $future = now()->addDay()->toDateString();

        $this->postJson('/attendance', [
            'employee_id' => $employee->employee_id,
            'status' => 'present',
            'date' => $future,
        ])->assertStatus(422);
    }

    public function test_employee_dashboard_does_not_expose_attendance_management_summary(): void
    {
        $this->actingAsEmployee();

        $response = $this->getJson('/dashboard/employee')->assertOk();
        $this->assertArrayNotHasKey('attendance_summary', $response->json('data') ?? []);
    }
}
