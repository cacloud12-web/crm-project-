<?php

namespace App\Services\Assignment;

use App\Models\AssignmentHistory;
use App\Models\CaMaster;
use App\Models\Employee;
use App\Models\LeadAssignmentEngine;
use App\Services\Activity\ActivityLogService;
use App\Services\Cache\CrmCacheService;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;

class AssignmentRecorder
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly NotificationService $notificationService,
        private readonly CrmCacheService $cacheService,
    ) {}

    public function assign(
        int $caId,
        int $employeeId,
        string $assignmentType,
        string $reason,
        ?int $assignedBy = null,
        string $activityAction = 'Lead Assignment',
        ?string $assignmentMode = 'manual',
    ): array {
        return DB::transaction(function () use ($caId, $employeeId, $assignmentType, $reason, $assignedBy, $activityAction, $assignmentMode) {
            $existing = LeadAssignmentEngine::query()
                ->where('ca_id', $caId)
                ->where('status', 'Active')
                ->latest('assignment_id')
                ->first();

            if ($existing && (int) $existing->employee_id === $employeeId) {
                return [
                    'status' => 'duplicate',
                    'assignment' => $existing,
                    'message' => 'duplicate_assignment: lead already assigned to this executive',
                ];
            }

            $previousEmployeeId = $existing?->employee_id;

            if ($existing) {
                $existing->update([
                    'employee_id' => $employeeId,
                    'assigned_date' => now()->toDateString(),
                    'assignment_type' => $assignmentType,
                    'rotation_logic_used' => $reason,
                ]);
                $assignment = $existing->fresh();
                $action = 'reassigned';
            } else {
                $assignment = LeadAssignmentEngine::create([
                    'ca_id' => $caId,
                    'employee_id' => $employeeId,
                    'assigned_date' => now()->toDateString(),
                    'assignment_type' => $assignmentType,
                    'rotation_logic_used' => $reason,
                    'priority_score' => 1,
                    'target_leads' => 0,
                    'achieved_leads' => 0,
                    'status' => 'Active',
                ]);
                $action = 'assigned';
            }

            AssignmentHistory::create([
                'ca_id' => $caId,
                'previous_employee_id' => $previousEmployeeId,
                'new_employee_id' => $employeeId,
                'assignment_type' => $assignmentType,
                'reason' => $reason,
                'assignment_mode' => $assignmentMode,
                'assigned_by' => $assignedBy,
                'assigned_at' => now(),
            ]);

            $firm = CaMaster::query()->where('ca_id', $caId)->value('firm_name') ?? 'Lead #'.$caId;
            $employeeName = Employee::query()->where('employee_id', $employeeId)->value('name') ?? 'Employee #'.$employeeId;
            $previousName = $previousEmployeeId
                ? (Employee::query()->where('employee_id', $previousEmployeeId)->value('name') ?? 'Employee #'.$previousEmployeeId)
                : null;
            $detail = $previousName
                ? "{$firm}: {$previousName} → {$employeeName} ({$reason})"
                : "{$firm} → {$employeeName} ({$reason})";

            $this->activityLogService->log(
                'LEAD_ASSIGNMENT_ENGINE',
                $activityAction,
                $this->shortId((string) $caId),
                $detail,
            );

            $employee = Employee::query()->where('employee_id', $employeeId)->first(['employee_id', 'name', 'email_id']);
            $employeeUserId = $this->notificationService->resolveUserIdByEmployeeEmail($employee?->email_id);
            $title = $action === 'reassigned' ? 'Lead reassigned' : 'New lead assigned';
            $message = $firm.' → '.$employeeName;
            $extra = [
                'entity_type' => 'ca_master',
                'entity_id' => (string) $caId,
                'payload' => [
                    'employee_id' => $employeeId,
                    'assignment_type' => $assignmentType,
                ],
            ];

            if ($employeeUserId) {
                $this->notificationService->notifyUser($employeeUserId, 'lead_assigned', $title, $message, $extra);
            }

            $this->notificationService->notifyManagement('lead_assigned', $title, $message, $extra);

            $affected = [$employeeId];
            if ($previousEmployeeId) {
                $affected[] = (int) $previousEmployeeId;
            }
            $this->cacheService->forgetDashboardMetricsAfterAssignment($affected);

            return [
                'status' => $action,
                'assignment' => $assignment,
                'message' => null,
            ];
        });
    }

    private function shortId(string $id): string
    {
        return strlen($id) <= 8 ? $id : substr($id, 0, 4).'…'.substr($id, -2);
    }
}
