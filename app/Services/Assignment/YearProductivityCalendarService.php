<?php

namespace App\Services\Assignment;

use App\Models\CompanyHoliday;
use App\Models\CompanyHolidayYear;
use App\Models\Employee;
use App\Models\YearlyEmployeeTarget;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use InvalidArgumentException;

class YearProductivityCalendarService
{
    /**
     * @return array<string, mixed>
     */
    public function buildYearSummary(int $year): array
    {
        $totalDays = $this->totalDaysInYear($year);
        $sundayCount = $this->countSundaysInYear($year);
        $holidayDates = $this->resolvedCompanyHolidayDates($year);
        $companyHolidayCount = count($holidayDates);
        $holidaysOnSunday = $this->countDatesOnSunday($holidayDates);
        $holidaysNotOnSunday = $companyHolidayCount - $holidaysOnSunday;
        $leaveAllowance = (int) config('yearly_productivity.leave_allowance', 12);
        $standardNonWorking = $this->yearlyNonWorkingDays();
        $targetWorkingDays = $this->targetWorkingDays($year);

        return [
            'year' => $year,
            'is_leap_year' => $totalDays === 366,
            'total_calendar_days' => $totalDays,
            'sunday_count' => $sundayCount,
            'company_holiday_count' => $companyHolidayCount,
            'company_holidays_on_sunday' => $holidaysOnSunday,
            'company_holidays_not_on_sunday' => $holidaysNotOnSunday,
            'employee_leave_allowance' => $leaveAllowance,
            'standard_non_working_days' => $standardNonWorking,
            'standard_countable_days' => $targetWorkingDays,
            'target_working_days' => $targetWorkingDays,
            'unique_calendar_non_working_days' => $sundayCount + $holidaysNotOnSunday,
        ];
    }

    public function yearlyNonWorkingDays(): int
    {
        return (int) config('crm_targets.yearly_non_working_days', 76);
    }

    public function targetWorkingDays(int $year): int
    {
        return $this->totalDaysInYear($year) - $this->yearlyNonWorkingDays();
    }

    /**
     * @return array<string, mixed>
     */
    public function buildEmployeeSummary(int $employeeId, int $year, ?YearlyEmployeeTarget $target = null, ?EmployeeLeaveService $leaveService = null): array
    {
        $leaveService ??= app(EmployeeLeaveService::class);
        $yearSummary = $this->buildYearSummary($year);
        $period = $this->resolveEmploymentPeriod($employeeId, $year);
        $allowance = (int) ($target?->annual_leave_allowance ?? config('yearly_productivity.leave_allowance', 12));
        $allowNegative = (bool) ($target?->allow_negative_leave_balance ?? false);

        $approvedLeaveUsed = $leaveService->approvedLeaveUsedOnWorkingDays($employeeId, $year);
        $remainingLeave = $allowNegative
            ? $allowance - $approvedLeaveUsed
            : max(0, $allowance - $approvedLeaveUsed);

        $effectiveTotal = $this->calculateEffectiveWorkingDays(
            $employeeId,
            $year,
            $period['start'],
            $period['end'],
        );

        $untilDate = min(now()->toDateString(), $period['end']);
        $effectiveElapsed = $this->calculateEffectiveWorkingDays(
            $employeeId,
            $year,
            $period['start'],
            $period['end'],
            $untilDate,
        );

        return array_merge($yearSummary, [
            'employee_id' => $employeeId,
            'annual_leave_allowance' => $allowance,
            'approved_leave_used' => $approvedLeaveUsed,
            'remaining_leave_balance' => $remainingLeave,
            'actual_effective_working_days_total' => $effectiveTotal,
            'actual_effective_working_days_elapsed' => $effectiveElapsed,
            'employment_period_start' => $period['start'],
            'employment_period_end' => $period['end'],
            'requires_proration_review' => $period['requires_review'],
            'proration_review_reason' => $period['review_reason'],
        ]);
    }

    public function totalDaysInYear(int $year): int
    {
        return Carbon::create($year, 1, 1)->isLeapYear() ? 366 : 365;
    }

    public function countSundaysInYear(int $year): int
    {
        return $this->countSundaysBetween(
            Carbon::create($year, 1, 1)->toDateString(),
            Carbon::create($year, 12, 31)->toDateString(),
        );
    }

    public function countSundaysBetween(string $startDate, string $endDate): int
    {
        $count = 0;
        foreach (CarbonPeriod::create($startDate, $endDate) as $date) {
            if ($date->isSunday()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    public function resolvedCompanyHolidayDates(int $year): array
    {
        $dates =         CompanyHoliday::query()
            ->with(['yearOverrides' => fn ($query) => $query->where('year', $year)])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (CompanyHoliday $holiday) => $holiday->dateForYear($year))
            ->unique()
            ->values()
            ->all();

        return $dates;
    }

    /**
     * @return array<string, string>
     */
    public function resolvedCompanyHolidayMap(int $year): array
    {
        $map = [];
        CompanyHoliday::query()
            ->with(['yearOverrides' => fn ($query) => $query->where('year', $year)])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->each(function (CompanyHoliday $holiday) use (&$map, $year) {
                $map[$holiday->dateForYear($year)] = $holiday->name;
            });

        return $map;
    }

    public function isSunday(string $date): bool
    {
        return Carbon::parse($date)->isSunday();
    }

    public function isCompanyHoliday(string $date, int $year): bool
    {
        return in_array($date, $this->resolvedCompanyHolidayDates($year), true);
    }

    public function isNonWorkingCalendarDay(string $date, int $year): bool
    {
        return $this->isSunday($date) || $this->isCompanyHoliday($date, $year);
    }

    public function calculateEffectiveWorkingDays(
        int $employeeId,
        int $year,
        string $periodStart,
        string $periodEnd,
        ?string $untilDate = null,
    ): int {
        $holidayDates = array_flip($this->resolvedCompanyHolidayDates($year));
        $approvedLeaves = array_flip(
            app(EmployeeLeaveService::class)->approvedLeaveDatesOnWorkingDays($employeeId, $year)
        );

        $count = 0;
        foreach (CarbonPeriod::create($periodStart, $periodEnd) as $date) {
            $dateString = $date->toDateString();
            if ($untilDate !== null && $dateString > $untilDate) {
                break;
            }
            if ($date->isSunday()) {
                continue;
            }
            if (isset($holidayDates[$dateString])) {
                continue;
            }
            if (isset($approvedLeaves[$dateString])) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    /**
     * @return array{start: string, end: string, requires_review: bool, review_reason: string|null}
     */
    public function resolveEmploymentPeriod(int $employeeId, int $year): array
    {
        $yearStart = Carbon::create($year, 1, 1)->toDateString();
        $yearEnd = Carbon::create($year, 12, 31)->toDateString();
        $employee = Employee::withTrashed()->find($employeeId);

        $start = $yearStart;
        $end = $yearEnd;
        $requiresReview = false;
        $reviewReason = null;

        if ($employee?->date_of_joining) {
            $joinDate = $employee->date_of_joining->toDateString();
            if ($joinDate > $yearEnd) {
                throw new InvalidArgumentException('Employee was not active during the selected year.');
            }
            if ($joinDate > $yearStart) {
                $start = $joinDate;
                $requiresReview = true;
                $reviewReason = 'Employee joined mid-year; leave allowance and targets may need manager confirmation.';
            }
        }

        if ($employee?->deleted_at) {
            $exitDate = $employee->deleted_at->toDateString();
            if ($exitDate >= $yearStart && $exitDate <= $yearEnd) {
                $end = min($end, $exitDate);
                $requiresReview = true;
                $reviewReason = $reviewReason
                    ?? 'Employee exited mid-year; leave allowance and targets may need manager confirmation.';
            }
        }

        return [
            'start' => $start,
            'end' => $end,
            'requires_review' => $requiresReview,
            'review_reason' => $reviewReason,
        ];
    }

    /**
     * @param  list<array{id?: int, holiday_date: string}>  $overrides
     * @return list<array<string, mixed>>
     */
    public function syncHolidayDatesForYear(int $year, array $overrides): array
    {
        $seenDates = [];

        foreach ($overrides as $row) {
            if (empty($row['id']) || empty($row['holiday_date'])) {
                continue;
            }

            $date = Carbon::parse($row['holiday_date']);
            if ((int) $date->year !== $year) {
                throw new InvalidArgumentException('Holiday date must belong to the selected year.');
            }

            $dateString = $date->toDateString();
            if (isset($seenDates[$dateString])) {
                throw new InvalidArgumentException('Duplicate company holiday date: '.$dateString);
            }
            $seenDates[$dateString] = true;

            CompanyHolidayYear::query()->updateOrCreate(
                [
                    'company_holiday_id' => (int) $row['id'],
                    'year' => $year,
                ],
                ['holiday_date' => $dateString],
            );
        }

        app(EmployeeCalendarService::class)->regenerateAllEmployeesForYear($year);

        return $this->listHolidaysForYear($year);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listHolidaysForYear(int $year): array
    {
        return CompanyHoliday::query()
            ->with(['yearOverrides' => fn ($query) => $query->where('year', $year)])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function (CompanyHoliday $holiday) use ($year) {
                $date = $holiday->dateForYear($year);
                $carbon = Carbon::parse($date);

                return [
                    'id' => $holiday->id,
                    'name' => $holiday->name,
                    'month' => (int) $holiday->month,
                    'day' => (int) $holiday->day,
                    'holiday_date' => $date,
                    'display_date' => $carbon->format('d M Y'),
                    'is_movable' => $holiday->isMovable(),
                    'falls_on_sunday' => $carbon->isSunday(),
                    'is_active' => (bool) $holiday->is_active,
                    'sort_order' => (int) $holiday->sort_order,
                ];
            })
            ->all();
    }

    /**
     * @param  list<string>  $dates
     */
    private function countDatesOnSunday(array $dates): int
    {
        $count = 0;
        foreach ($dates as $date) {
            if ($this->isSunday($date)) {
                $count++;
            }
        }

        return $count;
    }
}
