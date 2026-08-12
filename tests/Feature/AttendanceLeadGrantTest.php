<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\City;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\LeadAssignmentEngine;
use App\Models\SourceLead;
use App\Models\State;
use App\Models\User;
use App\Services\Attendance\AttendanceLeadGrantService;
use App\Services\Rbac\RbacDatabaseService;
use App\Services\Rbac\RbacMatrixService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CrmTestAccounts;
use Tests\TestCase;

class AttendanceLeadGrantTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('employee_attendances')) {
            $this->markTestSkipped('employee_attendances table is not migrated yet');
        }

        if (! Schema::hasColumn('employee_attendances', 'auto_leads_granted')) {
            $this->markTestSkipped('auto_leads_granted column is not migrated yet');
        }

        app(RbacDatabaseService::class)->ensureConfigDefaultGrants();
        app(RbacMatrixService::class)->flushCache();
    }

    private function actingAsSuperAdmin(): User
    {
        $user = CrmTestAccounts::superAdmin();
        $this->actingAs($user);

        return $user;
    }

    private function actingAsAdmin(): User
    {
        $user = CrmTestAccounts::admin();
        $this->actingAs($user);

        return $user;
    }

    private function grantEmployee(): Employee
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
            $this->markTestSkipped('No active team employee available');
        }

        return $employee;
    }

    private function createUnassignedLead(State $state, City $city, SourceLead $source, string $suffix): CaMaster
    {
        return CaMaster::query()->create([
            'firm_name' => 'Attendance Grant Firm '.$suffix,
            'ca_name' => 'Attendance CA '.$suffix,
            'mobile_no' => '9'.substr(str_pad((string) random_int(100000000, 999999999), 9, '0'), -9),
            'email_id' => 'att.grant.'.$suffix.'@test.local',
            'city_id' => $city->city_id,
            'state_id' => $state->state_id,
            'source_id' => $source->source_id,
            'status' => 'Hot',
            'rating' => 4,
            'team_size' => 5,
        ]);
    }

    public function test_super_admin_present_grants_unassigned_leads_once(): void
    {
        $this->actingAsSuperAdmin();
        $employee = $this->grantEmployee();
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();
        $source = SourceLead::query()->firstOrCreate(['source_name' => 'Attendance Grant '.uniqid()]);
        $today = now()->toDateString();

        EmployeeAttendance::query()
            ->where('employee_id', $employee->employee_id)
            ->whereDate('attendance_date', $today)
            ->delete();

        $leads = [];
        for ($i = 0; $i < 3; $i++) {
            $leads[] = $this->createUnassignedLead($state, $city, $source, 'p'.$i);
        }

        $assignedLead = $this->createUnassignedLead($state, $city, $source, 'assigned');
        LeadAssignmentEngine::query()->create([
            'ca_id' => $assignedLead->ca_id,
            'employee_id' => $employee->employee_id,
            'status' => 'Active',
            'assigned_date' => $today,
            'assignment_type' => 'Manual',
            'rotation_logic_used' => 'MANUAL_ASSIGN',
        ]);

        $beforeGrantCount = LeadAssignmentEngine::query()
            ->where('employee_id', $employee->employee_id)
            ->where('rotation_logic_used', AttendanceLeadGrantService::REASON)
            ->count();

        $response = $this->postJson('/attendance', [
            'employee_id' => $employee->employee_id,
            'status' => 'present',
            'date' => $today,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'present')
            ->assertJsonPath('data.lead_grant.target', AttendanceLeadGrantService::PRESENT_LEAD_COUNT)
            ->assertJsonPath('data.lead_grant.skipped', false);

        $granted = (int) $response->json('data.lead_grant.granted');
        $this->assertGreaterThan(0, $granted);
        $this->assertSame($granted, (int) $response->json('data.auto_leads_granted'));
        $this->assertSame(
            $beforeGrantCount + $granted,
            LeadAssignmentEngine::query()
                ->where('employee_id', $employee->employee_id)
                ->where('rotation_logic_used', AttendanceLeadGrantService::REASON)
                ->count()
        );

        $this->assertFalse(
            LeadAssignmentEngine::query()
                ->where('ca_id', $assignedLead->ca_id)
                ->where('rotation_logic_used', AttendanceLeadGrantService::REASON)
                ->exists()
        );

        $this->postJson('/attendance', [
            'employee_id' => $employee->employee_id,
            'status' => 'present',
            'date' => $today,
        ])->assertOk()
            ->assertJsonPath('data.lead_grant.skipped', true)
            ->assertJsonPath('data.lead_grant.reason', 'already_granted');

        $this->assertSame(
            $beforeGrantCount + $granted,
            LeadAssignmentEngine::query()
                ->where('employee_id', $employee->employee_id)
                ->where('rotation_logic_used', AttendanceLeadGrantService::REASON)
                ->count()
        );
    }

    public function test_super_admin_half_day_grants_100_target(): void
    {
        $this->actingAsSuperAdmin();
        $employee = $this->grantEmployee();
        $today = now()->toDateString();

        EmployeeAttendance::query()
            ->where('employee_id', $employee->employee_id)
            ->whereDate('attendance_date', $today)
            ->delete();

        $this->postJson('/attendance', [
            'employee_id' => $employee->employee_id,
            'status' => 'half_day',
            'date' => $today,
        ])->assertOk()
            ->assertJsonPath('data.lead_grant.target', AttendanceLeadGrantService::HALF_DAY_LEAD_COUNT);
    }

    public function test_absent_does_not_grant_leads(): void
    {
        $this->actingAsSuperAdmin();
        $employee = $this->grantEmployee();
        $today = now()->toDateString();

        EmployeeAttendance::query()
            ->where('employee_id', $employee->employee_id)
            ->whereDate('attendance_date', $today)
            ->delete();

        $before = LeadAssignmentEngine::query()
            ->where('employee_id', $employee->employee_id)
            ->where('rotation_logic_used', AttendanceLeadGrantService::REASON)
            ->count();

        $this->postJson('/attendance', [
            'employee_id' => $employee->employee_id,
            'status' => 'absent',
            'date' => $today,
        ])->assertOk()
            ->assertJsonPath('data.lead_grant.granted', 0)
            ->assertJsonPath('data.lead_grant.reason', 'absent_or_no_grant');

        $this->assertSame(
            $before,
            LeadAssignmentEngine::query()
                ->where('employee_id', $employee->employee_id)
                ->where('rotation_logic_used', AttendanceLeadGrantService::REASON)
                ->count()
        );
    }

    public function test_admin_marking_present_does_not_auto_assign(): void
    {
        $this->actingAsAdmin();
        $employee = $this->grantEmployee();
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
            ->assertJsonPath('data.lead_grant.granted', 0)
            ->assertJsonPath('data.lead_grant.reason', 'not_super_admin');

        $this->assertSame(
            0,
            LeadAssignmentEngine::query()
                ->where('employee_id', $employee->employee_id)
                ->where('rotation_logic_used', AttendanceLeadGrantService::REASON)
                ->count()
        );
    }
}
