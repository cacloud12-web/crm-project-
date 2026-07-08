<?php

namespace App\Services\Dashboard;

use App\Models\ActivityLog;
use App\Models\CaMaster;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\LeadAssignmentEngine;
use App\Models\Task;
use App\Services\Cache\CrmCacheService;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Services\Rbac\RbacService;
use App\Services\Leads\EmployeeProductivityService;
use App\Support\Database\SqlAggregate;
use Illuminate\Support\Facades\DB;

class EmployeeDashboardService
{
    private const OPEN_FOLLOWUP_STATUSES = ['Pending', 'Scheduled', 'Open', 'Overdue'];

    private const COMPLETED_FOLLOWUP_STATUSES = ['Completed', 'Closed'];

    private const DEMO_TYPES = ['Demo Scheduled', 'Demo Completed'];

    private const MEETING_TYPES = ['Demo Scheduled', 'Demo Completed', 'Meeting'];

    public function __construct(
        private readonly EmployeeDataScopeService $employeeDataScope,
        private readonly EmployeeProductivityService $employeeProductivity,
        private readonly CrmCacheService $cacheService,
    ) {}

    public function metrics(): array
    {
        $user = auth()->user();
        $employeeId = $this->requireEmployeeId($user);

        return $this->cacheService->rememberEmployeeDashboard($employeeId, function () use ($user, $employeeId) {
            return $this->buildMetrics($user, $employeeId);
        });
    }

    private function buildMetrics($user, int $employeeId): array
    {
        $employee = Employee::query()->with('city:city_id,city_name')->findOrFail($employeeId);

        $leadCounts = $this->leadStatusCounts($employeeId);
        $followUpCounts = $this->followUpSummary($employeeId);
        $taskCounts = $this->taskSummary($employeeId);
        $assignmentStats = $this->assignmentStats($employeeId);
        $productivity = $this->employeeProductivity->employeeDailyMetrics($employeeId);
        $today = now()->toDateString();

        return [
            'employee_id' => $employeeId,
            'welcome' => [
                'name' => $user->name,
                'designation' => app(RbacService::class)->roleLabel($user),
                'city' => $employee->city?->city_name,
                'date' => $today,
                'working_status' => $this->workingStatus($followUpCounts),
            ],
            'summary' => [
                'my_leads' => (int) ($leadCounts['total'] ?? 0),
                'my_followups' => $followUpCounts['total_open'],
                'my_demos' => $followUpCounts['demos_total'],
                'my_meetings' => $followUpCounts['meetings_today'],
                'todays_calls' => $followUpCounts['calls_today'],
                'todays_tasks' => $taskCounts['due_today'] + $taskCounts['overdue'],
                'pending_tasks' => $taskCounts['pending'],
                'overdue_tasks' => $taskCounts['overdue'],
                'completed_tasks_today' => $taskCounts['completed_today'],
                'upcoming_this_week' => $followUpCounts['upcoming_week'],
                'hot_leads' => (int) ($leadCounts['hot'] ?? 0),
                'warm_leads' => (int) ($leadCounts['warm'] ?? 0),
                'cold_leads' => (int) ($leadCounts['cold'] ?? 0),
                'conversion_pct' => $this->conversionPct($employeeId, (int) ($leadCounts['total'] ?? 0)),
                'todays_target' => $assignmentStats['target_today'],
                'todays_achievement' => $assignmentStats['achieved_today'],
            ],
            'productivity' => $productivity,
            'today_work' => [
                'followups_due' => $followUpCounts['due_today'],
                'followups_overdue' => $followUpCounts['overdue'],
                'meetings_today' => $followUpCounts['meetings_today'],
                'assigned_leads_today' => $assignmentStats['assigned_today'],
                'calls_today' => $followUpCounts['calls_today'],
                'upcoming_tasks' => $followUpCounts['upcoming'],
            ],
            'assigned_leads' => $this->recentAssignedLeads($employeeId),
            'followups' => $this->followUpBuckets($employeeId),
            'tasks' => $this->taskItems($employeeId),
            'calendar' => $this->calendarItems($employeeId),
            'recent_activity' => $this->recentActivity($user, $employee),
            'notifications_unread' => null,
        ];
    }

    private function requireEmployeeId($user): int
    {
        if (! $this->employeeDataScope->shouldScopeToEmployee($user)) {
            abort(403, 'Employee dashboard is only available to employees.');
        }

        $employeeId = $this->employeeDataScope->scopedEmployeeId($user);

        if (! $employeeId || $employeeId <= 0) {
            abort(403, 'No employee profile is linked to this account.');
        }

        return $employeeId;
    }

    private function leadStatusCounts(int $employeeId): array
    {
        $query = CaMaster::query()->countableInStatistics();
        $this->employeeDataScope->scopeCaMasterQuery($query, $employeeId);

        $row = $query
            ->selectRaw('COUNT(*) as total')
            ->selectRaw(SqlAggregate::countFilter('*', "status = 'Hot'").' as hot')
            ->selectRaw(SqlAggregate::countFilter('*', "status = 'Warm'").' as warm')
            ->selectRaw(SqlAggregate::countFilter('*', "status = 'Cold'").' as cold')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'hot' => (int) ($row->hot ?? 0),
            'warm' => (int) ($row->warm ?? 0),
            'cold' => (int) ($row->cold ?? 0),
        ];
    }

    private function followUpsQuery(int $employeeId): \Illuminate\Database\Eloquent\Builder
    {
        $query = FollowUp::query();
        $this->employeeDataScope->scopeFollowUpQuery($query, $employeeId);

        return $query;
    }

    private function followUpSummary(int $employeeId): array
    {
        $base = $this->followUpsQuery($employeeId);

        $open = $this->quotedList(self::OPEN_FOLLOWUP_STATUSES);
        $completed = $this->quotedList(self::COMPLETED_FOLLOWUP_STATUSES);
        $demos = $this->quotedList(self::DEMO_TYPES);
        $meetings = $this->quotedList(self::MEETING_TYPES);

        $row = (clone $base)
            ->selectRaw(SqlAggregate::countFilter('*', "status IN ({$open})").' as open_total')
            ->selectRaw(SqlAggregate::countFilter('*', "status IN ({$open}) AND DATE(scheduled_date) = CURRENT_DATE").' as due_today')
            ->selectRaw(SqlAggregate::countFilter('*', "status IN ({$open}) AND scheduled_date < CURRENT_DATE").' as overdue')
            ->selectRaw(SqlAggregate::countFilter('*', "status IN ({$completed})").' as completed')
            ->selectRaw(SqlAggregate::countFilter('*', "followup_type IN ({$demos})").' as demos_total')
            ->selectRaw(SqlAggregate::countFilter('*', "followup_type = 'Call' AND DATE(scheduled_date) = CURRENT_DATE").' as calls_today')
            ->selectRaw(SqlAggregate::countFilter('*', "followup_type IN ({$meetings}) AND DATE(scheduled_date) = CURRENT_DATE").' as meetings_today')
            ->selectRaw(SqlAggregate::countFilter('*', "status IN ({$open}) AND scheduled_date > CURRENT_DATE").' as upcoming')
            ->selectRaw(SqlAggregate::countFilter('*', "status IN ({$open}) AND DATE(scheduled_date) > CURRENT_DATE AND DATE(scheduled_date) <= ?").' as upcoming_week', [now()->addDays(7)->toDateString()])
            ->first();

        return [
            'total_open' => (int) ($row->open_total ?? 0),
            'due_today' => (int) ($row->due_today ?? 0),
            'overdue' => (int) ($row->overdue ?? 0),
            'completed' => (int) ($row->completed ?? 0),
            'demos_total' => (int) ($row->demos_total ?? 0),
            'calls_today' => (int) ($row->calls_today ?? 0),
            'meetings_today' => (int) ($row->meetings_today ?? 0),
            'upcoming' => (int) ($row->upcoming ?? 0),
            'upcoming_week' => (int) ($row->upcoming_week ?? 0),
        ];
    }

    private function taskSummary(int $employeeId): array
    {
        $today = now()->toDateString();
        $row = Task::query()
            ->where('employee_id', $employeeId)
            ->selectRaw(SqlAggregate::countFilter('*', "status IN ('Pending', 'Overdue')").' as pending')
            ->selectRaw(SqlAggregate::countFilter('*', "status = 'Overdue'").' as overdue')
            ->selectRaw(SqlAggregate::countFilter('*', "status IN ('Pending', 'Overdue') AND due_date = ?").' as due_today', [$today])
            ->selectRaw(SqlAggregate::countFilter('*', "status = 'Completed' AND DATE(completed_at) = ?").' as completed_today', [$today])
            ->first();

        return [
            'pending' => (int) ($row->pending ?? 0),
            'overdue' => (int) ($row->overdue ?? 0),
            'due_today' => (int) ($row->due_today ?? 0),
            'completed_today' => (int) ($row->completed_today ?? 0),
        ];
    }

    private function taskItems(int $employeeId): array
    {
        return Task::query()
            ->with(['caMaster:ca_id,firm_name'])
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['Pending', 'Overdue'])
            ->orderBy('due_date')
            ->limit(8)
            ->get()
            ->map(fn (Task $task) => [
                'task_id' => $task->task_id,
                'firm_name' => $task->caMaster?->firm_name ?? '—',
                'task_type' => $task->task_type,
                'due_date' => $task->due_date?->toDateString(),
                'status' => $task->status,
                'priority' => $task->priority,
            ])
            ->all();
    }

    private function assignmentStats(int $employeeId): array
    {
        $assignments = LeadAssignmentEngine::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'Active')
            ->get(['target_leads', 'achieved_leads', 'created_at']);

        $today = now()->toDateString();
        $assignedToday = LeadAssignmentEngine::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'Active')
            ->whereDate('assigned_date', $today)
            ->count();

        return [
            'target_today' => (int) $assignments->sum('target_leads'),
            'achieved_today' => (int) $assignments->sum('achieved_leads'),
            'assigned_today' => $assignedToday,
        ];
    }

    private function conversionPct(int $employeeId, int $totalLeads): float
    {
        if ($totalLeads === 0) {
            return 0.0;
        }

        $query = CaMaster::query()->countableInStatistics()->where('status', 'Won');
        $this->employeeDataScope->scopeCaMasterQuery($query, $employeeId);
        $won = (int) $query->count();

        return round(($won / $totalLeads) * 100, 1);
    }

    private function workingStatus(array $followUpCounts): string
    {
        if ($followUpCounts['overdue'] > 0) {
            return 'Overdue tasks pending';
        }

        if ($followUpCounts['due_today'] > 0) {
            return 'Tasks scheduled today';
        }

        return 'On track';
    }

    private function recentAssignedLeads(int $employeeId): array
    {
        return LeadAssignmentEngine::query()
            ->with(['caMaster:ca_id,firm_name,status,updated_at'])
            ->where('employee_id', $employeeId)
            ->where('status', 'Active')
            ->orderByDesc('assigned_date')
            ->orderByDesc('assignment_id')
            ->limit(8)
            ->get()
            ->map(function (LeadAssignmentEngine $assignment) {
                $lead = $assignment->caMaster;

                return [
                    'ca_id' => $lead?->ca_id,
                    'firm_name' => $lead?->firm_name ?? '—',
                    'status' => $lead?->status ?? '—',
                    'priority_score' => $assignment->priority_score,
                    'assigned_date' => $assignment->assigned_date?->toDateString(),
                    'updated_at' => $lead?->updated_at?->toIso8601String(),
                ];
            })
            ->filter(fn ($row) => $row['ca_id'] !== null)
            ->values()
            ->all();
    }

    /**
     * @return array{today: list<array<string, mixed>>, pending: list<array<string, mixed>>, completed: list<array<string, mixed>>, overdue: list<array<string, mixed>>}
     */
    private function followUpBuckets(int $employeeId): array
    {
        $today = now()->toDateString();
        $buckets = [
            'today' => [],
            'pending' => [],
            'completed' => [],
            'overdue' => [],
        ];

        $rows = $this->followUpsQuery($employeeId)
            ->with(['caMaster:ca_id,firm_name'])
            ->where(function ($query) {
                $query->whereIn('status', self::OPEN_FOLLOWUP_STATUSES)
                    ->orWhereIn('status', self::COMPLETED_FOLLOWUP_STATUSES);
            })
            ->orderBy('scheduled_date')
            ->limit(48)
            ->get()
            ->map(fn (FollowUp $f) => [
                'followup_id' => $f->followup_id,
                'firm_name' => $f->caMaster?->firm_name ?? '—',
                'followup_type' => $f->followup_type,
                'scheduled_date' => $f->scheduled_date?->toDateString(),
                'status' => $f->status,
                'priority' => $f->priority,
                'is_rescheduled' => $f->is_rescheduled,
                'remarks' => $f->remarks,
            ])
            ->all();

        foreach ($rows as $row) {
            $scheduled = $row['scheduled_date'] ?? '';
            $isOpen = in_array($row['status'], self::OPEN_FOLLOWUP_STATUSES, true);
            $isCompleted = in_array($row['status'], self::COMPLETED_FOLLOWUP_STATUSES, true);

            if ($isCompleted) {
                if (count($buckets['completed']) < 8) {
                    $buckets['completed'][] = $row;
                }
                continue;
            }

            if (! $isOpen) {
                continue;
            }

            if ($scheduled === $today && count($buckets['today']) < 8) {
                $buckets['today'][] = $row;
            }
            if ($scheduled !== '' && $scheduled < $today && count($buckets['overdue']) < 8) {
                $buckets['overdue'][] = $row;
            }
            if (count($buckets['pending']) < 8) {
                $buckets['pending'][] = $row;
            }
        }

        return $buckets;
    }

    private function followUpItems(int $employeeId, string $scope): array
    {
        $query = $this->followUpsQuery($employeeId)
            ->with(['caMaster:ca_id,firm_name']);

        match ($scope) {
            'today' => $query->whereIn('status', self::OPEN_FOLLOWUP_STATUSES)
                ->whereDate('scheduled_date', now()->toDateString()),
            'overdue' => $query->whereIn('status', self::OPEN_FOLLOWUP_STATUSES)
                ->whereDate('scheduled_date', '<', now()->toDateString()),
            'pending' => $query->whereIn('status', self::OPEN_FOLLOWUP_STATUSES),
            'completed' => $query->whereIn('status', self::COMPLETED_FOLLOWUP_STATUSES),
            default => null,
        };

        return $query
            ->orderBy('scheduled_date')
            ->limit(8)
            ->get()
            ->map(fn (FollowUp $f) => [
                'followup_id' => $f->followup_id,
                'firm_name' => $f->caMaster?->firm_name ?? '—',
                'followup_type' => $f->followup_type,
                'scheduled_date' => $f->scheduled_date?->toDateString(),
                'status' => $f->status,
                'priority' => $f->priority,
                'is_rescheduled' => $f->is_rescheduled,
                'remarks' => $f->remarks,
            ])
            ->all();
    }

    private function calendarItems(int $employeeId): array
    {
        return $this->followUpsQuery($employeeId)
            ->with(['caMaster:ca_id,firm_name'])
            ->whereIn('status', self::OPEN_FOLLOWUP_STATUSES)
            ->whereDate('scheduled_date', '>=', now()->toDateString())
            ->orderBy('scheduled_date')
            ->limit(12)
            ->get()
            ->map(fn (FollowUp $f) => [
                'followup_id' => $f->followup_id,
                'title' => ($f->followup_type ?? 'Follow-up').' · '.($f->caMaster?->firm_name ?? 'Lead'),
                'followup_type' => $f->followup_type,
                'firm_name' => $f->caMaster?->firm_name,
                'scheduled_date' => $f->scheduled_date?->toDateString(),
                'scheduled_time' => $f->scheduled_date?->format('H:i'),
                'is_today' => $f->scheduled_date?->isToday() ?? false,
            ])
            ->all();
    }

    private function recentActivity($user, Employee $employee): array
    {
        $performedBy = array_filter([$user->name, $user->email, $employee->name]);

        return ActivityLog::query()
            ->where(function ($outer) use ($performedBy) {
                foreach ($performedBy as $value) {
                    $outer->orWhere('performed_by', 'ilike', '%'.$value.'%');
                }
            })
            ->orderByDesc('created_at')
            ->limit(8)
            ->get(['id', 'module_name', 'action', 'description', 'performed_by', 'record_id', 'created_at'])
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'module_name' => $log->module_name,
                'action' => $log->action,
                'description' => $log->description,
                'performed_by' => $log->performed_by,
                'record_id' => $log->record_id,
                'timestamp' => $log->created_at?->toIso8601String(),
            ])
            ->all();
    }

    private function quotedList(array $values): string
    {
        return collect($values)
            ->map(fn (string $value) => DB::getPdo()->quote($value))
            ->implode(', ');
    }
}
