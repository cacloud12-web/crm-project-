<?php

namespace App\Services\Assignment;

use App\Models\LeadAssignmentEngine;
use App\Services\Activity\ActivityLogService;
use App\Services\Cache\CrmCacheService;
use App\Services\Concerns\SearchesListings;
use App\Services\Rbac\EmployeeDataScopeService;
use Illuminate\Support\Collection;

class LeadAssignmentService
{
    use SearchesListings;

    public function __construct(
        private readonly AssignmentRecorder $assignmentRecorder,
        private readonly ActivityLogService $activityLogService,
        private readonly EmployeeDataScopeService $employeeDataScope,
    ) {}

    public function search(array $params = []): array
    {
        return $this->searchListing(
            LeadAssignmentEngine::query()->with(['caMaster.city', 'employee.city']),
            $params,
            'lead_assignments',
        );
    }

    public function list(): Collection
    {
        return $this->listAllFromSearch(
            LeadAssignmentEngine::query()->with(['caMaster.city', 'employee.city']),
            [],
            'lead_assignments',
        );
    }

    public function find(int|string $id): LeadAssignmentEngine
    {
        app(EmployeeDataScopeService::class)->ensureCanAccessAssignment($id);

        return LeadAssignmentEngine::query()
            ->with(['caMaster.city', 'employee'])
            ->findOrFail($id);
    }

    public function assign(array $data): LeadAssignmentEngine
    {
        $result = $this->assignmentRecorder->assign(
            (int) $data['ca_id'],
            (int) $data['employee_id'],
            $data['assignment_type'] ?? 'Manual',
            $data['reason'] ?? 'MANUAL_ASSIGN',
            isset($data['assigned_by']) ? (int) $data['assigned_by'] : null,
        );

        return $result['assignment'];
    }

    public function update(LeadAssignmentEngine $assignment, array $data): LeadAssignmentEngine
    {
        $this->employeeDataScope->ensureCanAccessAssignment($assignment->assignment_id);

        $before = $assignment->only(['ca_id', 'employee_id', 'assignment_type', 'status']);
        $previousEmployeeId = (int) $assignment->employee_id;
        $newEmployeeId = isset($data['employee_id']) ? (int) $data['employee_id'] : $previousEmployeeId;

        $assignment->update([
            'ca_id' => $data['ca_id'] ?? $assignment->ca_id,
            'employee_id' => $newEmployeeId,
            'assignment_type' => $data['assignment_type'] ?? $assignment->assignment_type,
            'rotation_logic_used' => $data['reason'] ?? $assignment->rotation_logic_used,
            'assigned_date' => $newEmployeeId !== $previousEmployeeId ? now()->toDateString() : $assignment->assigned_date,
            'status' => $data['status'] ?? $assignment->status,
        ]);

        $this->activityLogService->log(
            'LEAD_ASSIGNMENT',
            'Assignment Update',
            (string) $assignment->assignment_id,
            json_encode(['before' => $before, 'after' => $assignment->only(['ca_id', 'employee_id', 'assignment_type', 'status'])]),
        );

        if ($newEmployeeId !== $previousEmployeeId) {
            app(CrmCacheService::class)
                ->forgetDashboardMetricsAfterAssignment([$previousEmployeeId, $newEmployeeId]);
        }

        return $assignment->fresh(['caMaster.city', 'employee']);
    }

    public function delete(LeadAssignmentEngine $assignment): void
    {
        $id = (string) $assignment->assignment_id;

        $this->activityLogService->log(
            'LEAD_ASSIGNMENT',
            'Assignment Delete',
            $id,
            'CA '.$assignment->ca_id.' · Employee '.$assignment->employee_id,
        );

        $assignment->delete();
    }
}
