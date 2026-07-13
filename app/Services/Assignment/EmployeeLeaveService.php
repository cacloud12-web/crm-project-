<?php

namespace App\Services\Assignment;

use App\Models\EmployeeCalendarDay;
use App\Models\EmployeeLeave;
use App\Models\User;
use App\Models\YearlyEmployeeTarget;
use App\Services\Cache\CrmCacheService;
use App\Services\Rbac\RbacService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EmployeeLeaveService
{
    public function __construct(
        private readonly YearProductivityCalendarService $calendarService,
        private readonly EmployeeCalendarService $employeeCalendarService,
        private readonly RbacService $rbacService,
        private readonly CrmCacheService $cacheService,
    ) {}

    public function canManage(?User $user = null): bool
    {
        $user ??= auth()->user();

        return in_array($this->rbacService->roleKey($user), ['super_admin', 'manager'], true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForEmployee(int $employeeId, int $year): array
    {
        return EmployeeLeave::query()
            ->where('employee_id', $employeeId)
            ->where('target_year', $year)
            ->orderByDesc('leave_date')
            ->get()
            ->map(fn (EmployeeLeave $leave) => $this->serialize($leave))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function requestLeave(array $payload, ?User $user = null): array
    {
        $user ??= auth()->user();
        $employeeId = (int) $payload['employee_id'];
        $leaveDate = Carbon::parse($payload['leave_date'])->toDateString();
        $year = (int) ($payload['target_year'] ?? Carbon::parse($leaveDate)->year);

        if ($this->calendarService->isNonWorkingCalendarDay($leaveDate, $year)) {
            throw new InvalidArgumentException('Leave cannot be requested on a Sunday or company holiday.');
        }

        $leave = EmployeeLeave::query()->updateOrCreate(
            [
                'employee_id' => $employeeId,
                'leave_date' => $leaveDate,
            ],
            [
                'target_year' => $year,
                'status' => EmployeeLeave::STATUS_PENDING,
                'reason' => $payload['reason'] ?? null,
                'requested_by' => $user?->id,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'counts_against_balance' => true,
            ],
        );

        return $this->serialize($leave);
    }

    public function approve(EmployeeLeave $leave, ?User $user = null): array
    {
        $user ??= auth()->user();
        $this->assertCanManage($user);

        if ($leave->status === EmployeeLeave::STATUS_APPROVED) {
            return $this->serialize($leave);
        }

        $year = (int) $leave->target_year;
        $date = $leave->leave_date->toDateString();

        if ($this->calendarService->isNonWorkingCalendarDay($date, $year)) {
            $leave->update([
                'status' => EmployeeLeave::STATUS_APPROVED,
                'counts_against_balance' => false,
                'reviewed_by' => $user?->id,
                'reviewed_at' => now(),
            ]);

            return $this->serialize($leave->fresh());
        }

        $target = YearlyEmployeeTarget::query()
            ->where('employee_id', $leave->employee_id)
            ->where('target_year', $year)
            ->first();

        $allowance = (int) ($target?->annual_leave_allowance ?? config('yearly_productivity.leave_allowance', 12));
        $used = $this->approvedLeaveUsedOnWorkingDays((int) $leave->employee_id, $year);
        $allowNegative = (bool) ($target?->allow_negative_leave_balance ?? false);

        if (! $allowNegative && $used >= $allowance) {
            throw new InvalidArgumentException('Employee has no remaining leave balance for this year.');
        }

        DB::transaction(function () use ($leave, $user, $target) {
            $leave->update([
                'status' => EmployeeLeave::STATUS_APPROVED,
                'counts_against_balance' => true,
                'reviewed_by' => $user?->id,
                'reviewed_at' => now(),
            ]);

            if ($target) {
                $this->employeeCalendarService->applyApprovedLeaveToCalendar($leave->fresh(), $target);
            }
            $this->cacheService->forgetYearlyEmployeeTargets((int) $leave->employee_id);
        });

        return $this->serialize($leave->fresh());
    }

    public function reject(EmployeeLeave $leave, ?User $user = null, ?string $reason = null): array
    {
        $user ??= auth()->user();
        $this->assertCanManage($user);

        $leave->update([
            'status' => EmployeeLeave::STATUS_REJECTED,
            'reason' => $reason ?: $leave->reason,
            'reviewed_by' => $user?->id,
            'reviewed_at' => now(),
        ]);

        return $this->serialize($leave->fresh());
    }

    public function approvedLeaveUsedOnWorkingDays(int $employeeId, int $year): int
    {
        return count($this->approvedLeaveDatesOnWorkingDays($employeeId, $year));
    }

    /**
     * @return list<string>
     */
    public function approvedLeaveDatesOnWorkingDays(int $employeeId, int $year): array
    {
        return EmployeeLeave::query()
            ->where('employee_id', $employeeId)
            ->where('target_year', $year)
            ->where('status', EmployeeLeave::STATUS_APPROVED)
            ->where('counts_against_balance', true)
            ->orderBy('leave_date')
            ->pluck('leave_date')
            ->map(fn ($date) => $date instanceof Carbon ? $date->toDateString() : (string) $date)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(EmployeeLeave $leave): array
    {
        return [
            'id' => $leave->id,
            'employee_id' => (int) $leave->employee_id,
            'leave_date' => $leave->leave_date?->toDateString(),
            'target_year' => (int) $leave->target_year,
            'status' => $leave->status,
            'reason' => $leave->reason,
            'counts_against_balance' => (bool) $leave->counts_against_balance,
            'reviewed_at' => $leave->reviewed_at?->toIso8601String(),
        ];
    }

    private function assertCanManage(?User $user): void
    {
        if (! $this->canManage($user)) {
            throw new InvalidArgumentException('Only managers and super admins can manage employee leave.');
        }
    }
}
