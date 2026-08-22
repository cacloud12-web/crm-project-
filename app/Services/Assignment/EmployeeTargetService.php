<?php

namespace App\Services\Assignment;

use App\Models\DailyEmployeeTarget;
use App\Models\DemoSchedule;
use App\Models\Employee;
use App\Models\EmployeeCalendarDay;
use App\Models\YearlyEmployeeTarget;
use App\Services\Dashboard\DemoMetricsService;
use Carbon\Carbon;

/**
 * Resolves daily targets from yearly calendar (or manager override) and builds progress snapshots.
 */
class EmployeeTargetService
{
    public function __construct(
        private readonly DailyEmployeeTargetProgressService $progressService,
        private readonly YearlyEmployeeTargetProgressService $yearlyProgressService,
        private readonly YearProductivityCalendarService $productivityCalendar,
        private readonly DemoMetricsService $demoMetrics,
    ) {}

    /**
     * @return array<string, int>
     */
    public function resolvedTargetsForDate(int $employeeId, Carbon|string $date): array
    {
        $dateString = $date instanceof Carbon ? $date->toDateString() : (string) $date;

        $override = DailyEmployeeTarget::query()
            ->where('employee_id', $employeeId)
            ->whereDate('target_date', $dateString)
            ->first();

        if ($override) {
            return [
                'lead_target' => (int) $override->lead_target,
                'call_target' => (int) $override->call_target,
                'demo_target' => (int) $override->demo_target,
                'followup_target' => (int) $override->followup_target,
                'email_target' => (int) $override->email_target,
                'sms_target' => (int) $override->sms_target,
                'source' => 'daily_override',
            ];
        }

        $calendarDay = EmployeeCalendarDay::query()
            ->where('employee_id', $employeeId)
            ->whereDate('calendar_date', $dateString)
            ->first();

        if ($calendarDay) {
            return [
                'lead_target' => (int) $calendarDay->lead_target,
                'call_target' => (int) $calendarDay->call_target,
                'demo_target' => (int) $calendarDay->demo_target,
                'followup_target' => (int) $calendarDay->followup_target,
                'email_target' => (int) $calendarDay->email_target,
                'sms_target' => (int) $calendarDay->sms_target,
                'source' => 'yearly_calendar',
                'day_type' => $calendarDay->day_type,
            ];
        }

        $year = (int) Carbon::parse($dateString)->year;
        $yearly = YearlyEmployeeTarget::query()
            ->where('employee_id', $employeeId)
            ->where('target_year', $year)
            ->first();

        if ($yearly) {
            return [
                'lead_target' => (int) $yearly->lead_target,
                'call_target' => (int) $yearly->call_target,
                'demo_target' => (int) $yearly->demo_target,
                'followup_target' => (int) $yearly->followup_target,
                'email_target' => (int) $yearly->email_target,
                'sms_target' => (int) $yearly->sms_target,
                'source' => 'yearly_rate',
            ];
        }

        return [
            'lead_target' => 0,
            'call_target' => 0,
            'demo_target' => 0,
            'followup_target' => 0,
            'email_target' => 0,
            'sms_target' => 0,
            'source' => 'none',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function todayProgress(int $employeeId, ?string $date = null): array
    {
        $date = $date ?? now()->toDateString();
        $targets = $this->resolvedTargetsForDate($employeeId, $date);
        $achievements = $this->progressService->achievementsForEmployee($employeeId, $date);

        $demoTarget = (int) ($targets['demo_target'] ?? 0);
        $demoAchieved = (int) ($achievements['demo_completed'] ?? 0);
        $demoRemaining = max(0, $demoTarget - $demoAchieved);
        $demoPct = $demoTarget > 0 ? round(($demoAchieved / $demoTarget) * 100, 1) : 0.0;

        $year = (int) Carbon::parse($date)->year;
        $yearly = YearlyEmployeeTarget::query()
            ->where('employee_id', $employeeId)
            ->where('target_year', $year)
            ->first();

        $yearlyBlock = [
            'has_target' => false,
            'target_year' => $year,
            'message' => 'No yearly target has been assigned for '.$year.'.',
        ];

        if ($yearly) {
            $progress = $this->yearlyProgressService->buildProgressPayload($yearly, $date);
            $demoMetric = collect($progress['metrics'] ?? [])->firstWhere('key', 'demo');
            $workingDays = (int) ($progress['standard_countable_days'] ?? $this->productivityCalendar->targetWorkingDays($year));
            $dailyDemoRate = (int) $yearly->demo_target;
            $yearlyDemoTarget = $dailyDemoRate * $workingDays;

            $yearlyBlock = [
                'has_target' => true,
                'target_year' => $year,
                'daily_demo_target' => $dailyDemoRate,
                'yearly_demo_target' => $yearlyDemoTarget,
                'ytd_demo_achieved' => (int) ($demoMetric['completed'] ?? 0),
                'ytd_demo_remaining' => max(0, (int) ($demoMetric['target'] ?? 0) - (int) ($demoMetric['completed'] ?? 0)),
                'ytd_demo_pct' => (float) ($demoMetric['pct'] ?? 0),
                'standard_countable_days' => $workingDays,
                'actual_effective_working_days_total' => (int) ($progress['actual_effective_working_days_total'] ?? 0),
                'actual_effective_working_days_elapsed' => (int) ($progress['actual_effective_working_days_elapsed'] ?? 0),
                'overall_pct' => (float) ($progress['overall_pct'] ?? 0),
                'status' => $progress['status'] ?? 'not_started',
                'status_label' => $progress['status_label'] ?? 'Not Started',
                'metrics' => $progress['metrics'] ?? [],
            ];
        }

        $hasTarget = $demoTarget > 0
            || (int) ($targets['lead_target'] ?? 0) > 0
            || (int) ($targets['call_target'] ?? 0) > 0
            || ($yearlyBlock['has_target'] ?? false);

        $metrics = $this->buildTodayMetrics($targets, $achievements);
        $dayStart = Carbon::parse($date)->startOfDay();
        $dayEnd = Carbon::parse($date)->endOfDay();
        $demoAggregate = $this->demoMetrics->aggregateForRange($employeeId, $dayStart, $dayEnd);

        return [
            'has_target' => $hasTarget,
            'target_date' => $date,
            'target_source' => $targets['source'] ?? 'none',
            'today' => [
                'demo_target' => $demoTarget,
                'demo_achieved' => $demoAchieved,
                'demo_remaining' => $demoRemaining,
                'demo_pct' => min(100.0, $demoPct),
                // Same rule as achievements.demo_completed (scheduled created today, not cancelled).
                'demos_scheduled_today' => $demoAchieved,
                'demos_completed_today' => (int) ($demoAggregate['demos_completed_today'] ?? 0),
                'demos_occuring_today' => $this->demoMetrics->demosOccurringOnDate($employeeId, $date),
            ],
            'yearly' => $yearlyBlock,
            'metrics' => $metrics,
            'demos' => $demoAggregate,
        ];
    }

    /**
     * Organization-wide daily target totals for active employees.
     *
     * @return array<string, mixed>
     */
    public function organizationTodayTotals(?array $employeeIds = null): array
    {
        $date = now()->toDateString();
        $ids = $employeeIds ?? Employee::query()->where('status', 'Active')->pluck('employee_id')->all();
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [
                'target_date' => $date,
                'daily_demo_target_total' => 0,
                'daily_demo_achieved_total' => 0,
                'daily_demo_remaining_total' => 0,
                'daily_demo_achievement_pct' => 0.0,
                'demos_scheduled_today' => 0,
                'demos_completed_today' => 0,
                'employee_count' => 0,
            ];
        }

        $totalTarget = 0;
        foreach ($ids as $employeeId) {
            $targets = $this->resolvedTargetsForDate($employeeId, $date);
            $totalTarget += (int) ($targets['demo_target'] ?? 0);
        }

        $achievementsByEmployee = $this->progressService->achievementsForEmployeesOnDate($ids, $date);
        $totalAchieved = 0;
        foreach ($ids as $employeeId) {
            $totalAchieved += (int) ($achievementsByEmployee[$employeeId]['demo_completed'] ?? 0);
        }

        [$start, $end] = $this->demoMetrics->dayBounds($date);
        $demosScheduledToday = (int) DemoSchedule::query()
            ->whereIn('employee_id', $ids)
            ->whereBetween('created_at', [$start, $end])
            ->whereNotIn('status', [DemoSchedule::STATUS_CANCELLED])
            ->distinct()
            ->count('demo_schedules.id');
        $demosCompletedToday = (int) DemoSchedule::query()
            ->whereIn('employee_id', $ids)
            ->where('status', DemoSchedule::STATUS_COMPLETED)
            ->whereBetween('updated_at', [$start, $end])
            ->distinct()
            ->count('demo_schedules.id');

        $remaining = max(0, $totalTarget - $totalAchieved);
        $pct = $totalTarget > 0 ? round(($totalAchieved / $totalTarget) * 100, 1) : 0.0;

        return [
            'target_date' => $date,
            'daily_demo_target_total' => $totalTarget,
            'daily_demo_achieved_total' => $totalAchieved,
            'daily_demo_remaining_total' => $remaining,
            'daily_demo_achievement_pct' => min(100.0, $pct),
            'demos_scheduled_today' => $demosScheduledToday,
            'demos_completed_today' => $demosCompletedToday,
            'employee_count' => count($ids),
        ];
    }

    /**
     * @param  array<string, int>  $targets
     * @param  array<string, int>  $achievements
     * @return list<array<string, mixed>>
     */
    private function buildTodayMetrics(array $targets, array $achievements): array
    {
        $defs = [
            ['key' => 'lead', 'label' => 'Leads', 'target_key' => 'lead_target', 'achievement_key' => 'lead_completed'],
            ['key' => 'call', 'label' => 'Calls', 'target_key' => 'call_target', 'achievement_key' => 'call_completed'],
            ['key' => 'demo', 'label' => 'Demos', 'target_key' => 'demo_target', 'achievement_key' => 'demo_completed'],
            ['key' => 'followup', 'label' => 'Follow-ups', 'target_key' => 'followup_target', 'achievement_key' => 'followup_completed'],
        ];

        return array_map(function (array $def) use ($targets, $achievements) {
            $target = max(0, (int) ($targets[$def['target_key']] ?? 0));
            $completed = max(0, (int) ($achievements[$def['achievement_key']] ?? 0));
            $pct = $target > 0 ? round(($completed / $target) * 100, 1) : 0.0;

            return [
                'key' => $def['key'],
                'label' => $def['label'],
                'target' => $target,
                'completed' => $completed,
                'remaining' => max(0, $target - $completed),
                'pct' => min(100.0, $pct),
            ];
        }, $defs);
    }

    /**
     * Demo schedule progress from when a demo target was assigned until today.
     *
     * @return array{target_demos: int, completed_demos: int, assigned_from: ?string}
     */
    public function demoProgressSinceAssignment(int $employeeId, ?int $year = null): array
    {
        $results = $this->demoProgressSinceAssignmentForEmployees([$employeeId], $year);

        return $results[$employeeId] ?? [
            'target_demos' => 0,
            'completed_demos' => 0,
            'assigned_from' => null,
        ];
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array<int, array{target_demos: int, completed_demos: int, assigned_from: ?string}>
     */
    public function demoProgressSinceAssignmentForEmployees(array $employeeIds, ?int $year = null): array
    {
        $year = $year ?? (int) now()->year;
        $today = now()->toDateString();
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));

        if ($ids === []) {
            return [];
        }

        $yearlyTargets = YearlyEmployeeTarget::query()
            ->whereIn('employee_id', $ids)
            ->where('target_year', $year)
            ->get()
            ->keyBy('employee_id');

        $calendarDaysByEmployee = EmployeeCalendarDay::query()
            ->whereIn('employee_id', $ids)
            ->where('day_type', EmployeeCalendarDay::TYPE_WORKING)
            ->whereYear('calendar_date', $year)
            ->whereDate('calendar_date', '<=', $today)
            ->orderBy('calendar_date')
            ->get(['employee_id', 'calendar_date', 'demo_target'])
            ->groupBy('employee_id');

        $dailyOverridesByEmployee = DailyEmployeeTarget::query()
            ->whereIn('employee_id', $ids)
            ->whereYear('target_date', $year)
            ->whereDate('target_date', '<=', $today)
            ->get(['employee_id', 'target_date', 'demo_target'])
            ->groupBy('employee_id');

        $firstDailyByEmployee = DailyEmployeeTarget::query()
            ->whereIn('employee_id', $ids)
            ->where('demo_target', '>', 0)
            ->whereYear('target_date', $year)
            ->orderBy('target_date')
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($rows) => $rows->first());

        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $demos = DemoSchedule::query()
            ->whereIn('employee_id', $ids)
            ->where('created_at', '>=', $yearStart)
            ->whereNotIn('status', [DemoSchedule::STATUS_CANCELLED])
            ->get(['employee_id', 'created_at']);

        $results = [];
        foreach ($ids as $employeeId) {
            $yearly = $yearlyTargets->get($employeeId);
            $assignedFrom = null;
            $targetTotal = 0;

            if ($yearly && (int) $yearly->demo_target > 0) {
                $assignedFrom = $yearly->created_at?->toDateString() ?? Carbon::create($year, 1, 1)->toDateString();
                $assignedFrom = max($assignedFrom, Carbon::create($year, 1, 1)->toDateString());

                $overrides = ($dailyOverridesByEmployee->get($employeeId) ?? collect())
                    ->mapWithKeys(fn (DailyEmployeeTarget $row) => [
                        $row->target_date instanceof Carbon
                            ? $row->target_date->toDateString()
                            : (string) $row->target_date => (int) $row->demo_target,
                    ]);

                foreach ($calendarDaysByEmployee->get($employeeId) ?? [] as $day) {
                    $dateString = $day->calendar_date instanceof Carbon
                        ? $day->calendar_date->toDateString()
                        : (string) $day->calendar_date;
                    if ($dateString < $assignedFrom) {
                        continue;
                    }
                    $targetTotal += (int) ($overrides[$dateString] ?? $day->demo_target);
                }

                if ($targetTotal === 0) {
                    $elapsedWorkingDays = ($calendarDaysByEmployee->get($employeeId) ?? collect())
                        ->filter(function ($day) use ($assignedFrom) {
                            $dateString = $day->calendar_date instanceof Carbon
                                ? $day->calendar_date->toDateString()
                                : (string) $day->calendar_date;

                            return $dateString >= $assignedFrom;
                        })
                        ->count();
                    $targetTotal = (int) $yearly->demo_target * max(0, $elapsedWorkingDays);
                }
            } else {
                $firstDaily = $firstDailyByEmployee->get($employeeId);
                if ($firstDaily) {
                    $assignedFrom = $firstDaily->target_date instanceof Carbon
                        ? $firstDaily->target_date->toDateString()
                        : (string) $firstDaily->target_date;
                    $targetTotal = (int) DailyEmployeeTarget::query()
                        ->where('employee_id', $employeeId)
                        ->whereDate('target_date', '>=', $assignedFrom)
                        ->whereDate('target_date', '<=', $today)
                        ->sum('demo_target');
                }
            }

            $completedTotal = 0;
            if ($assignedFrom) {
                $completedTotal = $demos
                    ->filter(fn (DemoSchedule $demo) => (int) $demo->employee_id === $employeeId
                        && $demo->created_at->toDateString() >= $assignedFrom)
                    ->count();
            }

            $results[$employeeId] = [
                'target_demos' => $targetTotal,
                'completed_demos' => $completedTotal,
                'assigned_from' => $assignedFrom,
            ];
        }

        return $results;
    }
}
