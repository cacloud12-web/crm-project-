<?php

namespace App\Services\Assignment;

use App\Models\Employee;
use App\Support\Database\SqlAggregate;
use App\Models\LeadAssignmentEngine;

class EmployeeAvailabilityService
{
    public const WORKLOAD_CAP = 50;

    /**
     * @param  list<int>  $employeeIds
     * @return array<int, int>
     */
    public function activeLeadCountsByEmployee(array $employeeIds): array
    {
        $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
        if ($employeeIds === []) {
            return [];
        }

        $rows = LeadAssignmentEngine::query()
            ->selectRaw('employee_id')
            ->selectRaw(SqlAggregate::countFilter('*', "status = 'Active'").' as active_leads')
            ->whereIn('employee_id', $employeeIds)
            ->groupBy('employee_id')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row->employee_id] = (int) $row->active_leads;
        }

        foreach ($employeeIds as $employeeId) {
            $counts[$employeeId] = $counts[$employeeId] ?? 0;
        }

        return $counts;
    }

    public function resolveUiStatus(?Employee $employee, int $activeLeads): string
    {
        if ($employee === null || $employee->trashed()) {
            return 'Offline';
        }

        if (strcasecmp((string) $employee->status, 'On Leave') === 0) {
            return 'Leave';
        }

        if (strcasecmp((string) $employee->status, 'Active') !== 0) {
            return 'Offline';
        }

        return $activeLeads >= (int) (self::WORKLOAD_CAP * 0.85) ? 'Busy' : 'Available';
    }
}
