<?php

namespace App\Services\Leads;

use App\Models\CaMaster;
use App\Models\LeadAssignmentEngine;
use App\Services\Assignment\EmployeeAvailabilityService;
use Illuminate\Support\Collection;

class LeadTeamMemberService
{
    public function __construct(
        private readonly EmployeeAvailabilityService $availabilityService,
    ) {}

    /**
     * @param  Collection<int, LeadAssignmentEngine>  $assignments
     * @return array{count: int, names: list<string>, lead_owner_id: int|null}
     */
    public function summarize(Collection $assignments): array
    {
        $names = [];
        $leadOwnerId = null;

        foreach ($assignments as $index => $assignment) {
            if ($index === 0) {
                $leadOwnerId = $assignment->employee_id ? (int) $assignment->employee_id : null;
            }

            $name = trim((string) ($assignment->employee?->name ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return [
            'count' => count($names),
            'names' => $names,
            'lead_owner_id' => $leadOwnerId,
        ];
    }

    /**
     * @return array{
     *     ca_id: int,
     *     firm_name: string|null,
     *     team_members_count: int,
     *     members: list<array{
     *         assignment_id: int,
     *         employee_id: int|null,
     *         name: string,
     *         role: string|null,
     *         availability_status: string,
     *         assigned_date: string|null,
     *         is_lead_owner: bool,
     *         is_active: bool
     *     }>
     * }
     */
    public function detailsForLead(CaMaster $lead): array
    {
        $lead->loadMissing([
            'activeTeamAssignments.employee:employee_id,name,role,status,deleted_at',
        ]);

        $assignments = $lead->activeTeamAssignments;
        $employeeIds = $assignments
            ->pluck('employee_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $workload = $this->availabilityService->activeLeadCountsByEmployee($employeeIds);
        $leadOwnerId = $assignments->first()?->employee_id;

        $members = $assignments->map(function (LeadAssignmentEngine $assignment) use ($workload, $leadOwnerId) {
            $employee = $assignment->employee;
            $employeeId = $assignment->employee_id ? (int) $assignment->employee_id : null;

            return [
                'assignment_id' => (int) $assignment->assignment_id,
                'employee_id' => $employeeId,
                'name' => $employee?->name ?? 'Unknown Employee',
                'role' => $employee?->role,
                'availability_status' => $this->availabilityService->resolveUiStatus(
                    $employee,
                    (int) ($workload[$employeeId] ?? 0),
                ),
                'assigned_date' => $assignment->assigned_date?->toDateString(),
                'is_lead_owner' => $employeeId !== null && (int) $leadOwnerId === $employeeId,
                'is_active' => $employee !== null && ! $employee->trashed()
                    && strcasecmp((string) $employee->status, 'Inactive') !== 0,
            ];
        })->values()->all();

        return [
            'ca_id' => (int) $lead->ca_id,
            'firm_name' => $lead->firm_name,
            'team_members_count' => count($members),
            'members' => $members,
        ];
    }
}
