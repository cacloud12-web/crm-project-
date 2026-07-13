<?php

namespace App\Services\Assignment;

use App\Models\AssignmentHistory;
use App\Models\LeadAssignmentEngine;
use App\Services\Activity\ActivityLogService;
use App\Services\Assignment\AssignmentRecorder;
use App\Services\Cache\CrmCacheService;
use App\Services\Concerns\SearchesListings;
use App\Services\Rbac\EmployeeDataScopeService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

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

        if (($result['status'] ?? '') === 'duplicate') {
            throw ValidationException::withMessages([
                'employee_id' => [$result['message'] ?? 'Lead is already assigned to this executive.'],
            ]);
        }

        return $result['assignment'];
    }

    public function update(LeadAssignmentEngine $assignment, array $data): LeadAssignmentEngine
    {
        $this->employeeDataScope->ensureCanAccessAssignment($assignment->assignment_id);

        $previousEmployeeId = (int) $assignment->employee_id;
        $newEmployeeId = isset($data['employee_id']) ? (int) $data['employee_id'] : $previousEmployeeId;

        if ($newEmployeeId !== $previousEmployeeId) {
            $assignedBy = $this->employeeDataScope->resolveEmployeeId(auth()->user());
            $result = $this->assignmentRecorder->assign(
                (int) ($data['ca_id'] ?? $assignment->ca_id),
                $newEmployeeId,
                $data['assignment_type'] ?? $assignment->assignment_type ?? 'Manual',
                $data['reason'] ?? $assignment->rotation_logic_used ?? 'REASSIGN',
                $assignedBy,
                'Lead Reassignment',
                'manual',
            );

            return $result['assignment']->load(['caMaster.city', 'employee']);
        }

        $before = $assignment->only(['ca_id', 'employee_id', 'assignment_type', 'status']);

        $assignment->update([
            'ca_id' => $data['ca_id'] ?? $assignment->ca_id,
            'assignment_type' => $data['assignment_type'] ?? $assignment->assignment_type,
            'rotation_logic_used' => $data['reason'] ?? $assignment->rotation_logic_used,
            'status' => $data['status'] ?? $assignment->status,
        ]);

        $this->activityLogService->log(
            'LEAD_ASSIGNMENT',
            'Assignment Update',
            (string) $assignment->assignment_id,
            json_encode(['before' => $before, 'after' => $assignment->only(['ca_id', 'employee_id', 'assignment_type', 'status'])]),
        );

        return $assignment->fresh(['caMaster.city', 'employee']);
    }

    public function setStatus(LeadAssignmentEngine $assignment, string $status): LeadAssignmentEngine
    {
        $this->employeeDataScope->ensureCanAccessAssignment($assignment->assignment_id);

        $status = ucfirst(strtolower($status));
        if (! in_array($status, ['Active', 'Paused'], true)) {
            abort(422, 'Invalid assignment status.');
        }

        $before = (string) $assignment->status;
        if ($before === $status) {
            return $assignment->fresh(['caMaster.city', 'employee']);
        }

        $assignment->update(['status' => $status]);

        $actionLabel = $status === 'Paused' ? 'Assignment Paused' : 'Assignment Resumed';

        $this->activityLogService->log(
            'LEAD_ASSIGNMENT',
            $actionLabel,
            (string) $assignment->assignment_id,
            json_encode([
                'ca_id' => $assignment->ca_id,
                'employee_id' => $assignment->employee_id,
                'before' => $before,
                'after' => $status,
            ]),
        );

        AssignmentHistory::create([
            'ca_id' => $assignment->ca_id,
            'previous_employee_id' => $assignment->employee_id,
            'new_employee_id' => $assignment->employee_id,
            'assignment_type' => $assignment->assignment_type,
            'reason' => $status === 'Paused' ? 'PAUSE_ASSIGNMENT' : 'RESUME_ASSIGNMENT',
            'assignment_mode' => 'status_change',
            'assigned_by' => $this->employeeDataScope->scopedEmployeeId(auth()->user()),
            'assigned_at' => now(),
        ]);

        app(CrmCacheService::class)
            ->forgetDashboardMetricsAfterAssignment([(int) $assignment->employee_id]);

        return $assignment->fresh(['caMaster.city', 'employee']);
    }

    public function delete(LeadAssignmentEngine $assignment): void
    {
        $this->employeeDataScope->ensureCanAccessAssignment($assignment->assignment_id);

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
