<?php

namespace App\Services\Attendance;

use App\Models\EmployeeAttendance;
use App\Models\User;
use App\Services\Assignment\BulkAssignmentCatalogService;
use App\Services\Assignment\BulkAssignmentService;
use App\Services\Rbac\RbacService;
use Illuminate\Support\Facades\Log;
use Throwable;

class AttendanceLeadGrantService
{
    public const REASON = 'ATTENDANCE_GRANT';

    public const PRESENT_LEAD_COUNT = 200;

    public const HALF_DAY_LEAD_COUNT = 100;

    public function __construct(
        private readonly RbacService $rbacService,
        private readonly BulkAssignmentCatalogService $catalogService,
        private readonly BulkAssignmentService $bulkAssignmentService,
    ) {}

    /**
     * @return array{
     *     granted: int,
     *     target: int,
     *     skipped: bool,
     *     reason: string|null,
     *     assigned_rows?: int,
     *     reassigned_rows?: int
     * }
     */
    public function grantForAttendance(EmployeeAttendance $record, User $marker): array
    {
        if ($this->rbacService->roleKey($marker) !== 'super_admin') {
            return $this->skip(0, 0, 'not_super_admin');
        }

        $attendanceDate = $record->attendance_date?->toDateString();
        if ($attendanceDate !== now()->toDateString()) {
            return $this->skip(0, 0, 'not_today');
        }

        $target = match ($record->status) {
            EmployeeAttendance::STATUS_PRESENT => self::PRESENT_LEAD_COUNT,
            EmployeeAttendance::STATUS_HALF_DAY => self::HALF_DAY_LEAD_COUNT,
            default => 0,
        };

        if ($target === 0) {
            return $this->skip(0, 0, 'absent_or_no_grant');
        }

        try {
            $locked = EmployeeAttendance::query()
                ->whereKey($record->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return $this->skip(0, $target, 'attendance_missing');
            }

            if ((int) $locked->auto_leads_granted > 0) {
                return [
                    'granted' => (int) $locked->auto_leads_granted,
                    'target' => $target,
                    'skipped' => true,
                    'reason' => 'already_granted',
                ];
            }

            $caIds = $this->catalogService->resolvePoolLeadIds('unassigned', [], $target);
            if ($caIds === []) {
                return $this->skip(0, $target, 'no_unassigned_leads');
            }

            $assignedBy = $this->rbacService->userPayload($marker)['employee_id'] ?? null;
            $summary = $this->bulkAssignmentService->execute([
                'ca_ids' => $caIds,
                'employee_ids' => [(int) $locked->employee_id],
                'assignment_mode' => 'manual',
                'reason' => self::REASON,
                'assigned_by' => $assignedBy ? (int) $assignedBy : null,
            ], preview: false);

            $granted = (int) ($summary['assigned_rows'] ?? 0) + (int) ($summary['reassigned_rows'] ?? 0);
            $locked->update(['auto_leads_granted' => $granted]);

            return [
                'granted' => $granted,
                'target' => $target,
                'skipped' => false,
                'reason' => null,
                'assigned_rows' => (int) ($summary['assigned_rows'] ?? 0),
                'reassigned_rows' => (int) ($summary['reassigned_rows'] ?? 0),
            ];
        } catch (Throwable $e) {
            Log::error('Attendance lead grant failed', [
                'attendance_id' => $record->id,
                'employee_id' => $record->employee_id,
                'status' => $record->status,
                'message' => $e->getMessage(),
            ]);

            return $this->skip(0, $target, 'grant_failed');
        }
    }

    /**
     * @return array{granted: int, target: int, skipped: bool, reason: string|null}
     */
    private function skip(int $granted, int $target, string $reason): array
    {
        return [
            'granted' => $granted,
            'target' => $target,
            'skipped' => true,
            'reason' => $reason,
        ];
    }
}
