<?php

namespace App\Services\Dashboard;

use App\Models\CaMaster;
use App\Models\FollowUp;
use App\Models\LeadAssignmentEngine;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Support\Database\SqlAggregate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Shared lead and follow-up snapshot counts for admin/manager dashboards.
 */
class DashboardMetricsService
{
    private const PIPELINE_STATUSES = [
        'Pipeline',
        'Warm',
        'Demo Scheduled',
        'Demo Completed',
        'Negotiation',
        'Details Shared',
    ];

    private const OPEN_FOLLOWUP_STATUSES = [
        'Pending',
        'Scheduled',
        'Open',
        'Overdue',
    ];

    public function __construct(
        private readonly EmployeeDataScopeService $employeeDataScope,
    ) {}

    /**
     * @return array<string, int>
     */
    public function leadSnapshotCounts(?int $employeeId): array
    {
        $query = CaMaster::query()->countableInStatistics();
        $this->employeeDataScope->scopeCaMasterQuery($query, $employeeId);

        $pipelineList = $this->quotedList(self::PIPELINE_STATUSES);
        $row = $query
            ->selectRaw('COUNT(*) as total')
            ->selectRaw(SqlAggregate::countFilter('*', "status = 'Hot'").' as hot')
            ->selectRaw(SqlAggregate::countFilter('*', "status = 'Warm'").' as warm')
            ->selectRaw(SqlAggregate::countFilter('*', "status = 'Cold'").' as cold')
            ->selectRaw(SqlAggregate::countFilter('*', "status = 'New'").' as status_new')
            ->selectRaw(SqlAggregate::countFilter('*', "status IN ({$pipelineList})").' as pipeline')
            ->selectRaw(SqlAggregate::countFilter('*', "status IN ('Lost', 'Inactive')").' as lost')
            ->selectRaw(SqlAggregate::countFilter('*', "status IN ('Active', 'Won') OR software_purchased = true").' as converted')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'hot' => (int) ($row->hot ?? 0),
            'warm' => (int) ($row->warm ?? 0),
            'cold' => (int) ($row->cold ?? 0),
            'status_new' => (int) ($row->status_new ?? 0),
            'pipeline' => (int) ($row->pipeline ?? 0),
            'lost' => (int) ($row->lost ?? 0),
            'converted' => (int) ($row->converted ?? 0),
        ];
    }

    public function newLeadsInPeriod(?int $employeeId, Carbon $from, Carbon $to): int
    {
        $query = CaMaster::query()->countableInStatistics();
        $this->employeeDataScope->scopeCaMasterQuery($query, $employeeId);
        $this->applyDateRange($query, 'ca_masters.created_at', $from, $to);

        return (int) $query
            ->where(function (Builder $q) {
                $q->where('is_newly_established', true)
                    ->orWhere('status', 'New');
            })
            ->count();
    }

    public function assignedLeadSnapshotCount(?int $employeeId): int
    {
        $query = LeadAssignmentEngine::query()
            ->where('lead_assignment_engines.status', 'Active')
            ->whereExists(function ($exists) {
                $exists->select(DB::raw(1))
                    ->from('ca_masters')
                    ->whereColumn('ca_masters.ca_id', 'lead_assignment_engines.ca_id')
                    ->where(function ($inner) {
                        $inner->whereNull('ca_masters.mobile_no_type')
                            ->orWhere('ca_masters.mobile_no_type', 'mobile');
                    });
            });
        $this->employeeDataScope->scopeLeadAssignmentQuery($query, $employeeId);

        return (int) $query->distinct()->count('lead_assignment_engines.ca_id');
    }

    /**
     * @return array{due_today: int, pending: int, overdue: int, completed: int}
     */
    public function followUpCounts(?int $employeeId, Carbon $from, Carbon $to): array
    {
        $todayStart = $from->copy()->startOfDay();
        $todayEnd = $from->copy()->endOfDay();
        $fromStart = $from->copy()->startOfDay();
        $toEnd = $to->copy()->endOfDay();
        $openList = $this->quotedList(['Pending', 'Scheduled', 'Open']);
        $overdueList = $this->quotedList(self::OPEN_FOLLOWUP_STATUSES);
        $completedList = $this->quotedList(['Completed', 'Closed']);

        $query = FollowUp::query();
        $this->employeeDataScope->scopeFollowUpQuery($query, $employeeId);

        $row = $query
            ->selectRaw(
                SqlAggregate::countFilter('*', "status IN ({$openList}) AND scheduled_date >= ? AND scheduled_date <= ?").' as due_today',
                [$todayStart, $todayEnd],
            )
            ->selectRaw(SqlAggregate::countFilter('*', "status IN ({$openList})").' as pending')
            ->selectRaw(
                SqlAggregate::countFilter('*', "status IN ({$overdueList}) AND scheduled_date < ?").' as overdue',
                [$todayStart],
            )
            ->selectRaw(
                SqlAggregate::countFilter('*', "status IN ({$completedList}) AND updated_at >= ? AND updated_at <= ?").' as completed',
                [$fromStart, $toEnd],
            )
            ->first();

        return [
            'due_today' => (int) ($row->due_today ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'overdue' => (int) ($row->overdue ?? 0),
            'completed' => (int) ($row->completed ?? 0),
        ];
    }

    public function callsInPeriod(?int $employeeId, Carbon $from, Carbon $to): int
    {
        $followUpCount = FollowUp::query()
            ->where('followup_type', 'Call');
        $this->employeeDataScope->scopeFollowUpQuery($followUpCount, $employeeId);
        $this->applyDateRange($followUpCount, 'scheduled_date', $from, $to);
        $fromFollowUps = (int) $followUpCount->count();

        $callLogCount = \App\Models\CallLog::query();
        $this->applyDateRange($callLogCount, 'called_at', $from, $to);
        if ($employeeId) {
            $callLogCount->where('employee_id', $employeeId);
        }

        return max($fromFollowUps, (int) $callLogCount->count());
    }

    public function meetingsInPeriod(?int $employeeId, Carbon $from, Carbon $to): int
    {
        $query = FollowUp::query()
            ->whereIn('followup_type', ['Demo Scheduled', 'Demo Completed', 'Meeting']);
        $this->employeeDataScope->scopeFollowUpQuery($query, $employeeId);
        $this->applyDateRange($query, 'scheduled_date', $from, $to);

        return (int) $query->count();
    }

    private function applyDateRange(Builder $query, string $column, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween($column, [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }

    private function quotedList(array $values): string
    {
        return collect($values)
            ->map(fn (string $value) => DB::getPdo()->quote($value))
            ->implode(', ');
    }
}
