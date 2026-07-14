<?php

namespace Tests\Feature;

use App\Models\DemoSchedule;
use App\Models\Employee;
use App\Models\User;
use App\Models\YearlyEmployeeTarget;
use App\Services\Assignment\EmployeeCalendarService;
use App\Services\Cache\CrmCacheService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DashboardTargetMetricsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_daily_demo_target_is_derived_from_yearly_assignment(): void
    {
        $employee = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $year = (int) now()->year;

        $yearly = YearlyEmployeeTarget::query()->updateOrCreate(
            ['employee_id' => $employee->employee_id, 'target_year' => $year],
            [
                'lead_target' => 2,
                'call_target' => 5,
                'demo_target' => 4,
                'followup_target' => 3,
                'annual_leave_allowance' => 12,
            ],
        );

        app(EmployeeCalendarService::class)->regenerateForTarget($yearly);

        $this->actingAs(User::query()->where('email', 'employee@ca.local')->firstOrFail());
        $response = $this->getJson('/dashboard/employee')->assertOk();

        $progress = $response->json('data.target_progress') ?? [];
        $this->assertTrue($progress['has_target'] ?? false);
        $this->assertSame(4, (int) ($progress['today']['demo_target'] ?? 0));
        $this->assertGreaterThan(0, (int) ($progress['yearly']['yearly_demo_target'] ?? 0));
    }

    public function test_scheduling_demo_updates_employee_and_admin_dashboard_counts(): void
    {
        $employee = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $user = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $year = (int) now()->year;

        YearlyEmployeeTarget::query()->updateOrCreate(
            ['employee_id' => $employee->employee_id, 'target_year' => $year],
            [
                'lead_target' => 2,
                'call_target' => 5,
                'demo_target' => 4,
                'followup_target' => 3,
                'annual_leave_allowance' => 12,
            ],
        );

        app(EmployeeCalendarService::class)->regenerateForTarget(
            YearlyEmployeeTarget::query()
                ->where('employee_id', $employee->employee_id)
                ->where('target_year', $year)
                ->firstOrFail()
        );

        app(CrmCacheService::class)->forgetEmployeeDashboard((int) $employee->employee_id);
        app(CrmCacheService::class)->forgetDashboardMetrics();

        $before = DemoSchedule::query()
            ->where('employee_id', $employee->employee_id)
            ->whereDate('created_at', now()->toDateString())
            ->count();

        $lead = \App\Models\CaMaster::query()
            ->whereHas('leadAssignments', fn ($q) => $q
                ->where('employee_id', $employee->employee_id)
                ->where('status', 'Active'))
            ->first();

        if (! $lead) {
            $this->markTestSkipped('No assigned lead available for demo scheduling test.');
        }

        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);
        $this->postJson('/workflow/demos', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'demo_at' => now()->addDay()->setTime(15, 0)->toDateTimeString(),
            'meeting_link' => 'https://meet.example.com/demo-test',
            'notes' => 'Dashboard metrics test',
        ])->assertOk();

        $this->actingAs($user);
        $after = DemoSchedule::query()
            ->where('employee_id', $employee->employee_id)
            ->whereDate('created_at', now()->toDateString())
            ->count();

        $this->assertSame($before + 1, $after);

        app(CrmCacheService::class)->forgetEmployeeDashboard((int) $employee->employee_id);
        app(CrmCacheService::class)->forgetDashboardMetrics();

        $employeeDashboard = $this->getJson('/dashboard/employee')->assertOk();
        $this->assertSame($after, (int) $employeeDashboard->json('data.summary.my_demos'));
        $this->assertSame($after, (int) $employeeDashboard->json('data.summary.todays_achievement'));

        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);
        $adminDashboard = $this->getJson('/dashboard/metrics?employee_id='.$employee->employee_id)->assertOk();
        $this->assertSame($after, (int) $adminDashboard->json('data.demos_scheduled_today'));
        $this->assertSame($after, (int) $adminDashboard->json('data.organization_target.daily_demo_achieved_total'));
    }

    public function test_rescheduling_demo_does_not_increment_scheduled_count(): void
    {
        $employee = Employee::query()->where('email_id', 'employee@ca.local')->firstOrFail();
        $schedule = DemoSchedule::query()
            ->where('employee_id', $employee->employee_id)
            ->whereDate('created_at', now()->toDateString())
            ->latest('id')
            ->first();

        if (! $schedule) {
            $this->markTestSkipped('No demo scheduled today to reschedule.');
        }

        $before = DemoSchedule::query()
            ->where('employee_id', $employee->employee_id)
            ->whereDate('created_at', now()->toDateString())
            ->whereNotIn('status', [DemoSchedule::STATUS_CANCELLED])
            ->count();

        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
        $this->actingAs($admin);

        $this->patchJson('/demo-calendar/schedules/'.$schedule->id.'/reschedule', [
            'demo_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'demo_end_at' => now()->addDays(2)->addHour()->format('Y-m-d H:i:s'),
            'meeting_link' => $schedule->meeting_link ?: 'https://meet.example.com/reschedule',
        ])->assertOk();

        $after = DemoSchedule::query()
            ->where('employee_id', $employee->employee_id)
            ->whereDate('created_at', now()->toDateString())
            ->whereNotIn('status', [DemoSchedule::STATUS_CANCELLED])
            ->count();

        $this->assertSame($before, $after);
    }
}
