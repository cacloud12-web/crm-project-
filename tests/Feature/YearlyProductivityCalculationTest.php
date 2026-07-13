<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\CompanyHoliday;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\State;
use App\Models\YearlyEmployeeTarget;
use App\Services\Assignment\YearProductivityCalendarService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class YearlyProductivityCalculationTest extends TestCase
{
    use DatabaseTransactions;

    private YearProductivityCalendarService $calendarService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calendarService = app(YearProductivityCalendarService::class);
    }

    private function createEmployee(array $overrides = []): Employee
    {
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();

        return Employee::query()->create(array_merge([
            'name' => 'Productivity Exec '.random_int(100, 999),
            'email_id' => 'prod.exec.'.random_int(100, 999).'@test.local',
            'mobile_no' => '9'.substr(str_pad((string) random_int(100000000, 999999999), 9, '0'), -9),
            'role' => 'Sales Executive',
            'city_id' => $city->city_id,
            'status' => 'Active',
            'date_of_joining' => '2026-01-01',
        ], $overrides));
    }

    public function test_normal_year_target_working_days_are_289(): void
    {
        $summary = $this->calendarService->buildYearSummary(2026);

        $this->assertSame(365, $summary['total_calendar_days']);
        $this->assertSame(52, $summary['sunday_count']);
        $this->assertSame(289, $summary['target_working_days']);
        $this->assertSame(289, $summary['standard_countable_days']);
        $this->assertSame(76, $summary['standard_non_working_days']);
        $this->assertSame(289, $this->calendarService->targetWorkingDays(2026));
    }

    public function test_leap_year_target_working_days_are_290(): void
    {
        $summary = $this->calendarService->buildYearSummary(2028);

        $this->assertSame(366, $summary['total_calendar_days']);
        $this->assertSame(290, $summary['target_working_days']);
        $this->assertSame(290, $summary['standard_countable_days']);
        $this->assertSame(290, $this->calendarService->targetWorkingDays(2028));
    }

    public function test_company_holiday_on_sunday_is_counted_once_in_unique_calendar_non_working(): void
    {
        CompanyHoliday::query()->create([
            'name' => 'Test Sunday Holiday',
            'month' => 1,
            'day' => 4,
            'is_active' => true,
            'sort_order' => 99,
        ]);

        $summary = $this->calendarService->buildYearSummary(2026);

        $this->assertGreaterThan(0, $summary['company_holidays_on_sunday']);
        $this->assertSame(
            $summary['sunday_count'] + $summary['company_holidays_not_on_sunday'],
            $summary['unique_calendar_non_working_days'],
        );
    }

    public function test_duplicate_holiday_dates_are_rejected(): void
    {
        $holidays = CompanyHoliday::query()->where('is_active', true)->orderBy('id')->take(2)->get();
        $this->assertCount(2, $holidays);

        $this->expectException(\InvalidArgumentException::class);

        $this->calendarService->syncHolidayDatesForYear(2026, [
            ['id' => $holidays[0]->id, 'holiday_date' => '2026-03-10'],
            ['id' => $holidays[1]->id, 'holiday_date' => '2026-03-10'],
        ]);
    }

    public function test_approved_leave_on_working_day_reduces_effective_days(): void
    {
        $employee = $this->createEmployee(['date_of_joining' => '2026-01-01']);
        YearlyEmployeeTarget::query()->create([
            'employee_id' => $employee->employee_id,
            'target_year' => 2026,
            'lead_target' => 5,
            'annual_leave_allowance' => 12,
        ]);

        $leaveDate = '2026-03-02';
        $this->assertFalse($this->calendarService->isNonWorkingCalendarDay($leaveDate, 2026));

        EmployeeLeave::query()->create([
            'employee_id' => $employee->employee_id,
            'leave_date' => $leaveDate,
            'target_year' => 2026,
            'status' => EmployeeLeave::STATUS_APPROVED,
            'counts_against_balance' => true,
        ]);

        $summary = $this->calendarService->buildEmployeeSummary($employee->employee_id, 2026);
        $this->assertSame(1, $summary['approved_leave_used']);
        $this->assertSame(11, $summary['remaining_leave_balance']);

        EmployeeLeave::query()->where('employee_id', $employee->employee_id)->delete();
        $baseline = $this->calendarService->buildEmployeeSummary($employee->employee_id, 2026);
        $this->assertSame(
            $baseline['actual_effective_working_days_total'] - 1,
            $summary['actual_effective_working_days_total'],
        );
    }

    public function test_pending_leave_does_not_reduce_balance(): void
    {
        $employee = $this->createEmployee(['date_of_joining' => '2026-01-01']);

        EmployeeLeave::query()->create([
            'employee_id' => $employee->employee_id,
            'leave_date' => '2026-04-06',
            'target_year' => 2026,
            'status' => EmployeeLeave::STATUS_PENDING,
        ]);

        $summary = $this->calendarService->buildEmployeeSummary($employee->employee_id, 2026);
        $this->assertSame(0, $summary['approved_leave_used']);
        $this->assertSame(12, $summary['remaining_leave_balance']);
    }

    public function test_mid_year_join_flags_proration_review(): void
    {
        $employee = $this->createEmployee(['date_of_joining' => '2026-06-15']);
        $summary = $this->calendarService->buildEmployeeSummary($employee->employee_id, 2026);

        $this->assertTrue($summary['requires_proration_review']);
        $this->assertSame('2026-06-15', $summary['employment_period_start']);
    }
}
