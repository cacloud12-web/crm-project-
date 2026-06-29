<?php

namespace App\Services\Assignment;

use App\Models\ActivityLog;
use App\Models\AssignmentHistory;
use App\Models\CaMaster;
use App\Models\Employee;
use App\Models\LeadAssignmentEngine;
use App\Services\Activity\ActivityLogService;
use App\Services\Cache\CrmCacheService;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;

class BulkAssignmentWriter
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly NotificationService $notificationService,
        private readonly CrmCacheService $cacheService,
    ) {}

    /**
     * @param  array<int, array{ca_id:int, employee_id:int, assignment_type:string, reason:string, assignment_mode:string}>  $rows
     */
    public function commit(array $rows, ?int $assignedBy, string $assignmentMode, ?string $ipAddress = null): array
    {
        if ($rows === []) {
            return ['assigned' => 0, 'reassigned' => 0, 'duplicate' => 0];
        }

        $caIds = array_values(array_unique(array_column($rows, 'ca_id')));
        $employeeIds = array_values(array_unique(array_column($rows, 'employee_id')));

        $existing = LeadAssignmentEngine::query()
            ->whereIn('ca_id', $caIds)
            ->where('status', 'Active')
            ->get()
            ->keyBy('ca_id');

        $firmNames = CaMaster::query()->whereIn('ca_id', $caIds)->pluck('firm_name', 'ca_id');
        $employeeNames = Employee::query()->whereIn('employee_id', $employeeIds)->pluck('name', 'employee_id');

        $assigned = 0;
        $reassigned = 0;
        $duplicate = 0;
        $affectedEmployeeIds = [];
        $now = now();
        $today = $now->toDateString();
        $performer = $this->activityLogService->resolvePerformer(null);
        $ip = $this->activityLogService->resolveIpAddress($ipAddress) ?? '127.0.0.1';
        $historyRows = [];
        $activityRows = [];
        $employeeNotifications = [];

        DB::transaction(function () use (
            $rows,
            $existing,
            $firmNames,
            $employeeNames,
            $assignedBy,
            $assignmentMode,
            $now,
            $today,
            $performer,
            $ip,
            &$assigned,
            &$reassigned,
            &$duplicate,
            &$historyRows,
            &$activityRows,
            &$employeeNotifications,
            &$affectedEmployeeIds,
        ) {
            foreach (array_chunk($rows, 200) as $chunk) {
                foreach ($chunk as $row) {
                    $caId = (int) $row['ca_id'];
                    $employeeId = (int) $row['employee_id'];
                    $current = $existing->get($caId);

                    if ($current && (int) $current->employee_id === $employeeId) {
                        $duplicate++;

                        continue;
                    }

                    $previousEmployeeId = $current?->employee_id;
                    $firm = $firmNames[$caId] ?? 'Lead #'.$caId;
                    $employeeName = $employeeNames[$employeeId] ?? 'Employee #'.$employeeId;
                    $previousName = $previousEmployeeId
                        ? ($employeeNames[$previousEmployeeId] ?? 'Employee #'.$previousEmployeeId)
                        : null;

                    if ($current) {
                        $current->update([
                            'employee_id' => $employeeId,
                            'assigned_date' => $today,
                            'assignment_type' => $row['assignment_type'],
                            'rotation_logic_used' => $row['reason'],
                        ]);
                        $existing->put($caId, $current->fresh());
                        $reassigned++;
                        $action = 'reassigned';
                    } else {
                        $created = LeadAssignmentEngine::create([
                            'ca_id' => $caId,
                            'employee_id' => $employeeId,
                            'assigned_date' => $today,
                            'assignment_type' => $row['assignment_type'],
                            'rotation_logic_used' => $row['reason'],
                            'priority_score' => 1,
                            'target_leads' => 0,
                            'achieved_leads' => 0,
                            'status' => 'Active',
                        ]);
                        $existing->put($caId, $created);
                        $assigned++;
                        $action = 'assigned';
                    }

                    $historyRows[] = [
                        'ca_id' => $caId,
                        'previous_employee_id' => $previousEmployeeId,
                        'new_employee_id' => $employeeId,
                        'assignment_type' => $row['assignment_type'],
                        'reason' => $row['reason'],
                        'assignment_mode' => $assignmentMode,
                        'assigned_by' => $assignedBy,
                        'assigned_at' => $now,
                        'ip_address' => $ip,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $detail = $previousName
                        ? "{$firm}: {$previousName} → {$employeeName} ({$row['reason']})"
                        : "{$firm} → {$employeeName} ({$row['reason']})";

                    $activityRows[] = [
                        'performed_by' => $performer,
                        'module_name' => 'LEAD_ASSIGNMENT_ENGINE',
                        'record_id' => $this->shortId((string) $caId),
                        'action' => 'Bulk Assignment',
                        'description' => $detail,
                        'before_value' => $previousEmployeeId ? json_encode(['employee_id' => $previousEmployeeId, 'name' => $previousName]) : null,
                        'after_value' => json_encode(['employee_id' => $employeeId, 'name' => $employeeName, 'mode' => $assignmentMode]),
                        'ip_address' => $ip,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $employeeNotifications[$employeeId] = ($employeeNotifications[$employeeId] ?? 0) + 1;
                    $affectedEmployeeIds[] = $employeeId;
                    if ($previousEmployeeId) {
                        $affectedEmployeeIds[] = (int) $previousEmployeeId;
                    }
                }

                if ($historyRows !== []) {
                    AssignmentHistory::query()->insert($historyRows);
                    $historyRows = [];
                }
                if ($activityRows !== []) {
                    ActivityLog::query()->insert($activityRows);
                    $activityRows = [];
                }
            }
        });

        $this->cacheService->forgetDashboardMetricsAfterAssignment($affectedEmployeeIds);
        $this->notifySummary($employeeNotifications, $assigned + $reassigned, $assignmentMode);

        return [
            'assigned' => $assigned,
            'reassigned' => $reassigned,
            'duplicate' => $duplicate,
        ];
    }

    private function notifySummary(array $employeeCounts, int $total, string $mode): void
    {
        if ($total <= 0) {
            return;
        }

        $title = 'Bulk lead assignment completed';
        $message = sprintf('%d leads assigned via %s', $total, str_replace('_', ' ', $mode));
        $this->notificationService->notifyManagement('lead_assigned', $title, $message, [
            'entity_type' => 'bulk_assignment',
            'payload' => ['total' => $total, 'mode' => $mode],
        ]);

        foreach ($employeeCounts as $employeeId => $count) {
            $employee = Employee::query()->where('employee_id', $employeeId)->first(['email_id']);
            $userId = $this->notificationService->resolveUserIdByEmployeeEmail($employee?->email_id);
            if ($userId) {
                $this->notificationService->notifyUser(
                    $userId,
                    'lead_assigned',
                    'New leads assigned',
                    $count.' lead(s) assigned to you',
                    ['entity_type' => 'bulk_assignment', 'payload' => ['count' => $count]],
                );
            }
        }
    }

    private function shortId(string $id): string
    {
        return strlen($id) <= 8 ? $id : substr($id, 0, 4).'…'.substr($id, -2);
    }
}
