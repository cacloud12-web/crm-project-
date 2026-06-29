<?php

namespace App\Services\FollowUp;

use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\Task;

class ManagerFollowUpDashboardService
{
    private const OPEN = ['Pending', 'Scheduled', 'Open', 'Overdue'];

    private const COMPLETED = ['Completed', 'Closed'];

    public function metrics(): array
    {
        $today = now()->toDateString();
        $weekEnd = now()->addDays(7)->toDateString();

        return [
            'today' => FollowUp::query()->whereIn('status', self::OPEN)->whereDate('scheduled_date', $today)->count(),
            'upcoming' => FollowUp::query()->whereIn('status', self::OPEN)->whereDate('scheduled_date', '>', $today)->count(),
            'completed_today' => FollowUp::query()->whereIn('status', self::COMPLETED)->whereDate('updated_at', $today)->count(),
            'missed' => FollowUp::query()
                ->whereIn('status', ['Pending', 'Scheduled', 'Open'])
                ->whereDate('scheduled_date', '<', $today)
                ->count(),
            'overdue' => FollowUp::query()->where('status', 'Overdue')->count(),
            'upcoming_this_week' => FollowUp::query()
                ->whereIn('status', self::OPEN)
                ->whereDate('scheduled_date', '>', $today)
                ->whereDate('scheduled_date', '<=', $weekEnd)
                ->count(),
            'followup_conversion_pct' => $this->followupConversionPct(),
            'demo_conversion_pct' => $this->demoConversionPct(),
            'employees' => $this->employeeBreakdown(),
        ];
    }

    private function followupConversionPct(): float
    {
        $totalCalls = FollowUp::query()->where('followup_type', 'Call')->count();
        if ($totalCalls === 0) {
            return 0.0;
        }

        $positive = FollowUp::query()
            ->where('followup_type', 'Call')
            ->whereIn('status', self::COMPLETED)
            ->whereIn('outcome', ['Interested', 'Demo Scheduled', 'Demo Completed'])
            ->count();

        return round(($positive / $totalCalls) * 100, 1);
    }

    private function demoConversionPct(): float
    {
        $scheduled = FollowUp::query()->where('followup_type', 'Demo Scheduled')->count();
        if ($scheduled === 0) {
            return 0.0;
        }

        $completed = FollowUp::query()
            ->where('followup_type', 'Demo Completed')
            ->whereIn('status', self::COMPLETED)
            ->count();

        return round(($completed / $scheduled) * 100, 1);
    }

    private function employeeBreakdown(): array
    {
        return Employee::query()
            ->where('status', 'Active')
            ->orderBy('name')
            ->get(['employee_id', 'name'])
            ->map(function (Employee $employee) {
                return [
                    'employee_id' => $employee->employee_id,
                    'name' => $employee->name,
                    'pending_followups' => FollowUp::query()
                        ->where('employee_id', $employee->employee_id)
                        ->whereIn('status', ['Pending', 'Scheduled', 'Open'])
                        ->count(),
                    'overdue_followups' => FollowUp::query()
                        ->where('employee_id', $employee->employee_id)
                        ->where('status', 'Overdue')
                        ->count(),
                    'pending_tasks' => Task::query()
                        ->where('employee_id', $employee->employee_id)
                        ->whereIn('status', ['Pending', 'Overdue'])
                        ->count(),
                ];
            })
            ->all();
    }
}
