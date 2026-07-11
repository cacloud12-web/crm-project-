<?php

namespace App\Services\Assignment;

use App\Models\CompanyHoliday;
use App\Models\EmployeeCalendarDay;
use App\Models\YearlyEmployeeTarget;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EmployeeCalendarService
{
    /**
     * @return list<string>
     */
    public function companyHolidayDatesForYear(int $year): array
    {
        return CompanyHoliday::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (CompanyHoliday $holiday) => $holiday->dateForYear($year))
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function companyHolidayMapForYear(int $year): array
    {
        $map = [];
        CompanyHoliday::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->each(function (CompanyHoliday $holiday) use (&$map, $year) {
                $map[$holiday->dateForYear($year)] = $holiday->name;
            });

        return $map;
    }

    public function regenerateForTarget(YearlyEmployeeTarget $target): void
    {
        $year = (int) $target->target_year;
        $employeeId = (int) $target->employee_id;
        $holidayMap = $this->companyHolidayMapForYear($year);
        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = Carbon::create($year, 12, 31)->startOfDay();
        $rows = [];

        foreach (CarbonPeriod::create($start, $end) as $date) {
            $dateString = $date->toDateString();
            $isSunday = $date->isSunday();
            $holidayName = $holidayMap[$dateString] ?? null;

            if ($isSunday) {
                $dayType = EmployeeCalendarDay::TYPE_SUNDAY;
                $label = 'Sunday';
                $lead = $call = $demo = $followup = $email = $sms = 0;
            } elseif ($holidayName !== null) {
                $dayType = EmployeeCalendarDay::TYPE_HOLIDAY;
                $label = $holidayName;
                $lead = $call = $demo = $followup = $email = $sms = 0;
            } else {
                $dayType = EmployeeCalendarDay::TYPE_WORKING;
                $label = null;
                $lead = (int) $target->lead_target;
                $call = (int) $target->call_target;
                $demo = (int) $target->demo_target;
                $followup = (int) $target->followup_target;
                $email = (int) $target->email_target;
                $sms = (int) $target->sms_target;
            }

            $rows[] = [
                'employee_id' => $employeeId,
                'yearly_employee_target_id' => $target->id,
                'calendar_date' => $dateString,
                'day_type' => $dayType,
                'holiday_name' => $label,
                'lead_target' => $lead,
                'call_target' => $call,
                'demo_target' => $demo,
                'followup_target' => $followup,
                'email_target' => $email,
                'sms_target' => $sms,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::transaction(function () use ($employeeId, $year, $rows) {
            EmployeeCalendarDay::query()
                ->where('employee_id', $employeeId)
                ->whereYear('calendar_date', $year)
                ->delete();

            foreach (array_chunk($rows, 200) as $chunk) {
                EmployeeCalendarDay::query()->insert($chunk);
            }
        });
    }

    public function regenerateAllEmployeesForYear(int $year): void
    {
        YearlyEmployeeTarget::query()
            ->where('target_year', $year)
            ->get()
            ->each(fn (YearlyEmployeeTarget $target) => $this->regenerateForTarget($target));
    }

    /**
     * @return Collection<int, EmployeeCalendarDay>
     */
    public function workingDaysUpTo(int $employeeId, int $year, ?string $untilDate = null): Collection
    {
        $untilDate ??= min(now()->toDateString(), Carbon::create($year, 12, 31)->toDateString());

        return EmployeeCalendarDay::query()
            ->where('employee_id', $employeeId)
            ->whereYear('calendar_date', $year)
            ->where('day_type', EmployeeCalendarDay::TYPE_WORKING)
            ->whereDate('calendar_date', '<=', $untilDate)
            ->orderBy('calendar_date')
            ->get();
    }

    public function workingDayCountForYear(int $employeeId, int $year): int
    {
        return EmployeeCalendarDay::query()
            ->where('employee_id', $employeeId)
            ->whereYear('calendar_date', $year)
            ->where('day_type', EmployeeCalendarDay::TYPE_WORKING)
            ->count();
    }

    public function workingDaysElapsed(int $employeeId, int $year, ?string $untilDate = null): int
    {
        return $this->workingDaysUpTo($employeeId, $year, $untilDate)->count();
    }
}
