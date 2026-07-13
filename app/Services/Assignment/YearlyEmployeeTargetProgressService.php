<?php

namespace App\Services\Assignment;

use App\Models\YearlyEmployeeTarget;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class YearlyEmployeeTargetProgressService
{
    public function __construct(
        private readonly DailyEmployeeTargetProgressService $dailyProgressService,
        private readonly EmployeeCalendarService $calendarService,
        private readonly YearProductivityCalendarService $productivityCalendar,
        private readonly EmployeeLeaveService $leaveService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildProgressPayload(YearlyEmployeeTarget $target, ?string $untilDate = null): array
    {
        $year = (int) $target->target_year;
        $employeeId = (int) $target->employee_id;
        $untilDate ??= min(now()->toDateString(), Carbon::create($year, 12, 31)->toDateString());

        $yearSummary = $this->productivityCalendar->buildYearSummary($year);
        $employeeSummary = $this->productivityCalendar->buildEmployeeSummary($employeeId, $year, $target, $this->leaveService);

        $workingDays = $this->calendarService->workingDaysUpTo($employeeId, $year, $untilDate);
        $workingDates = $workingDays->pluck('calendar_date')->map(fn ($d) => $d->toDateString())->all();

        $achievements = $this->achievementsOnWorkingDays($employeeId, $workingDates);

        $standardCountableDays = (int) $yearSummary['standard_countable_days'];
        $actualElapsedDays = max(1, (int) $employeeSummary['actual_effective_working_days_elapsed']);
        $actualTotalDays = (int) $employeeSummary['actual_effective_working_days_total'];

        $metrics = $this->metricDefinitions(
            $target,
            $achievements,
            $standardCountableDays,
            $actualElapsedDays,
            $actualTotalDays,
        );

        $overall = $this->overallProgress($metrics, useActual: true);
        $status = $this->resolveStatus($overall['raw_pct'], $year, $untilDate);

        return [
            'metrics' => $metrics,
            'overall_pct' => $overall['display_pct'],
            'overall_raw_pct' => $overall['raw_pct'],
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'achievements' => $achievements,
            'working_days_elapsed' => $actualElapsedDays,
            'working_days_total' => $actualTotalDays,
            'standard_countable_days' => $standardCountableDays,
            'standard_non_working_days' => (int) $yearSummary['standard_non_working_days'],
            'actual_effective_working_days_total' => $actualTotalDays,
            'actual_effective_working_days_elapsed' => $actualElapsedDays,
            'approved_leave_used' => (int) $employeeSummary['approved_leave_used'],
            'remaining_leave_balance' => (int) $employeeSummary['remaining_leave_balance'],
            'annual_leave_allowance' => (int) $employeeSummary['annual_leave_allowance'],
            'requires_proration_review' => (bool) $employeeSummary['requires_proration_review'],
            'proration_review_reason' => $employeeSummary['proration_review_reason'],
            'calendar_summary' => $yearSummary,
            'year_end_date' => Carbon::create($year, 12, 31)->toDateString(),
            'progress_through' => $untilDate,
        ];
    }

    /**
     * @param  list<string>  $workingDates
     * @return array<string, int>
     */
    private function achievementsOnWorkingDays(int $employeeId, array $workingDates): array
    {
        if ($workingDates === []) {
            return [
                'lead_completed' => 0,
                'call_completed' => 0,
                'demo_completed' => 0,
                'followup_completed' => 0,
                'email_completed' => 0,
                'sms_completed' => 0,
            ];
        }

        $totals = [
            'lead_completed' => 0,
            'call_completed' => 0,
            'demo_completed' => 0,
            'followup_completed' => 0,
            'email_completed' => 0,
            'sms_completed' => 0,
        ];

        foreach ($workingDates as $date) {
            $day = $this->dailyProgressService->achievementsForEmployee($employeeId, $date);
            foreach ($totals as $key => $value) {
                $totals[$key] += (int) ($day[$key] ?? 0);
            }
        }

        return $totals;
    }

    /**
     * @param  array<string, int>  $achievements
     * @return list<array<string, mixed>>
     */
    private function metricDefinitions(
        YearlyEmployeeTarget $target,
        array $achievements,
        int $standardCountableDays,
        int $actualElapsedDays,
        int $actualTotalDays,
    ): array {
        $defs = [
            ['key' => 'lead', 'label' => 'Leads', 'daily_target' => (int) $target->lead_target, 'completed' => (int) $achievements['lead_completed']],
            ['key' => 'call', 'label' => 'Calls', 'daily_target' => (int) $target->call_target, 'completed' => (int) $achievements['call_completed']],
            ['key' => 'demo', 'label' => 'Demos', 'daily_target' => (int) $target->demo_target, 'completed' => (int) $achievements['demo_completed']],
            ['key' => 'followup', 'label' => 'Follow-ups', 'daily_target' => (int) $target->followup_target, 'completed' => (int) $achievements['followup_completed']],
        ];

        if ((int) $target->email_target > 0) {
            $defs[] = ['key' => 'email', 'label' => 'Emails', 'daily_target' => (int) $target->email_target, 'completed' => (int) $achievements['email_completed']];
        }

        if ((int) $target->sms_target > 0) {
            $defs[] = ['key' => 'sms', 'label' => 'SMS', 'daily_target' => (int) $target->sms_target, 'completed' => (int) $achievements['sms_completed']];
        }

        return array_map(function (array $row) use ($standardCountableDays, $actualElapsedDays, $actualTotalDays) {
            $dailyTarget = max(0, (int) $row['daily_target']);
            $standardYearlyTarget = $dailyTarget * $standardCountableDays;
            $actualElapsedTarget = $dailyTarget * max(1, $actualElapsedDays);
            $actualYearlyTarget = $dailyTarget * max(1, $actualTotalDays);
            $completed = max(0, (int) $row['completed']);
            $pct = $actualElapsedTarget > 0 ? round(($completed / $actualElapsedTarget) * 100, 1) : 0.0;

            return [
                'key' => $row['key'],
                'label' => $row['label'],
                'daily_target' => $dailyTarget,
                'standard_yearly_target' => $standardYearlyTarget,
                'actual_yearly_target' => $actualYearlyTarget,
                'target' => $actualElapsedTarget,
                'completed' => $completed,
                'remaining' => max(0, $actualElapsedTarget - $completed),
                'pct' => min(100.0, $pct),
                'raw_pct' => $pct,
            ];
        }, $defs);
    }

    /**
     * @param  list<array<string, mixed>>  $metrics
     * @return array{display_pct: float, raw_pct: float}
     */
    private function overallProgress(array $metrics, bool $useActual = true): array
    {
        $enabled = array_values(array_filter($metrics, fn (array $m) => ($m['target'] ?? 0) > 0));

        if ($enabled === []) {
            return ['display_pct' => 0.0, 'raw_pct' => 0.0];
        }

        $assigned = array_sum(array_column($enabled, 'target'));
        $completed = array_sum(array_column($enabled, 'completed'));
        $raw = $assigned > 0 ? round(($completed / $assigned) * 100, 1) : 0.0;

        return [
            'display_pct' => min(100.0, $raw),
            'raw_pct' => $raw,
        ];
    }

    private function resolveStatus(float $rawPct, int $year, string $untilDate): string
    {
        $yearEnd = Carbon::create($year, 12, 31)->toDateString();
        $isPastYear = $year < (int) now()->year || ($year === (int) now()->year && $untilDate >= $yearEnd && now()->toDateString() > $yearEnd);

        if ($rawPct <= 0) {
            return $isPastYear ? 'missed' : 'not_started';
        }

        if ($rawPct > 100) {
            return 'exceeded';
        }

        if ($rawPct >= 100) {
            return 'completed';
        }

        return $isPastYear ? 'missed' : 'in_progress';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'not_started' => 'Not Started',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'exceeded' => 'Exceeded',
            'missed' => 'Missed',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}
