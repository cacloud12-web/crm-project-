<?php

namespace App\Services\FollowUp;

use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\Task;
use App\Support\Database\SqlAggregate;
use Illuminate\Support\Facades\DB;

class ManagerFollowUpDashboardService
{
    private const OPEN = ['Pending', 'Scheduled', 'Open', 'Overdue'];

    private const COMPLETED = ['Completed', 'Closed'];

    public function metrics(): array
    {
        $openList = $this->quotedList(self::OPEN);
        $completedList = $this->quotedList(self::COMPLETED);

        $todayStart = now()->startOfDay();
        $todayEnd = now()->endOfDay();
        $weekEndAt = now()->addDays(7)->endOfDay();

        $summary = FollowUp::query()
            ->selectRaw(
                SqlAggregate::countFilter('*', "status IN ({$openList}) AND scheduled_date >= ? AND scheduled_date <= ?").' as today',
                [$todayStart, $todayEnd],
            )
            ->selectRaw(
                SqlAggregate::countFilter('*', "status IN ({$openList}) AND scheduled_date > ?").' as upcoming',
                [$todayEnd],
            )
            ->selectRaw(
                SqlAggregate::countFilter('*', "status IN ({$completedList}) AND updated_at >= ? AND updated_at <= ?").' as completed_today',
                [$todayStart, $todayEnd],
            )
            ->selectRaw(
                SqlAggregate::countFilter('*', "status IN ('Pending', 'Scheduled', 'Open') AND scheduled_date < ?").' as missed',
                [$todayStart],
            )
            ->selectRaw(SqlAggregate::countFilter('*', "status = 'Overdue'").' as overdue')
            ->selectRaw(
                SqlAggregate::countFilter('*', "status IN ({$openList}) AND scheduled_date > ? AND scheduled_date <= ?").' as upcoming_this_week',
                [$todayEnd, $weekEndAt],
            )
            ->selectRaw(SqlAggregate::countFilter('*', "followup_type = 'Call'").' as call_total')
            ->selectRaw(
                SqlAggregate::countFilter('*', "followup_type = 'Call' AND status IN ({$completedList}) AND outcome IN ('Interested', 'Demo Scheduled', 'Demo Completed')").' as call_positive',
            )
            ->selectRaw(SqlAggregate::countFilter('*', "followup_type = 'Demo Scheduled'").' as demo_scheduled')
            ->selectRaw(
                SqlAggregate::countFilter('*', "followup_type = 'Demo Completed' AND status IN ({$completedList})").' as demo_completed',
            )
            ->first();

        $totalCalls = (int) ($summary->call_total ?? 0);
        $positiveCalls = (int) ($summary->call_positive ?? 0);
        $demoScheduled = (int) ($summary->demo_scheduled ?? 0);
        $demoCompleted = (int) ($summary->demo_completed ?? 0);

        return [
            'today' => (int) ($summary->today ?? 0),
            'upcoming' => (int) ($summary->upcoming ?? 0),
            'completed_today' => (int) ($summary->completed_today ?? 0),
            'missed' => (int) ($summary->missed ?? 0),
            'overdue' => (int) ($summary->overdue ?? 0),
            'upcoming_this_week' => (int) ($summary->upcoming_this_week ?? 0),
            'followup_conversion_pct' => $totalCalls ? round(($positiveCalls / $totalCalls) * 100, 1) : 0.0,
            'demo_conversion_pct' => $demoScheduled ? round(($demoCompleted / $demoScheduled) * 100, 1) : 0.0,
            'employees' => $this->employeeBreakdown(),
        ];
    }

    private function employeeBreakdown(): array
    {
        $followupStats = FollowUp::query()
            ->whereNotNull('employee_id')
            ->selectRaw('employee_id')
            ->selectRaw(SqlAggregate::countFilter('*', "status IN ('Pending', 'Scheduled', 'Open')").' as pending_followups')
            ->selectRaw(SqlAggregate::countFilter('*', "status = 'Overdue'").' as overdue_followups')
            ->groupBy('employee_id')
            ->get()
            ->keyBy('employee_id');

        $taskStats = Task::query()
            ->whereNotNull('employee_id')
            ->whereIn('status', ['Pending', 'Overdue'])
            ->selectRaw('employee_id, COUNT(*) as pending_tasks')
            ->groupBy('employee_id')
            ->pluck('pending_tasks', 'employee_id');

        return Employee::query()
            ->where('status', 'Active')
            ->orderBy('name')
            ->get(['employee_id', 'name'])
            ->map(function (Employee $employee) use ($followupStats, $taskStats) {
                $stats = $followupStats->get($employee->employee_id);

                return [
                    'employee_id' => $employee->employee_id,
                    'name' => $employee->name,
                    'pending_followups' => (int) ($stats->pending_followups ?? 0),
                    'overdue_followups' => (int) ($stats->overdue_followups ?? 0),
                    'pending_tasks' => (int) ($taskStats->get($employee->employee_id) ?? 0),
                ];
            })
            ->all();
    }

    private function quotedList(array $values): string
    {
        return collect($values)
            ->map(fn (string $value) => DB::getPdo()->quote($value))
            ->implode(', ');
    }
}
