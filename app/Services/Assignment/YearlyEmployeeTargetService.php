<?php

namespace App\Services\Assignment;

use App\Models\Employee;
use App\Models\EmployeeCalendarDay;
use App\Models\User;
use App\Models\YearlyEmployeeTarget;
use App\Services\Activity\ActivityLogService;
use App\Services\Cache\CrmCacheService;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Services\Rbac\RbacService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use InvalidArgumentException;

class YearlyEmployeeTargetService
{
    public function __construct(
        private readonly YearlyEmployeeTargetProgressService $progressService,
        private readonly EmployeeCalendarService $calendarService,
        private readonly YearProductivityCalendarService $productivityCalendar,
        private readonly RbacService $rbacService,
        private readonly EmployeeDataScopeService $employeeDataScope,
        private readonly ActivityLogService $activityLogService,
        private readonly CrmCacheService $cacheService,
    ) {}

    public function canEdit(?User $user = null): bool
    {
        $user ??= auth()->user();

        return in_array($this->rbacService->roleKey($user), ['super_admin', 'admin', 'manager'], true);
    }

    public function canViewMonitoring(?User $user = null): bool
    {
        $user ??= auth()->user();

        return in_array($this->rbacService->roleKey($user), ['super_admin', 'admin', 'manager'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function list(array $filters, ?User $user = null): array
    {
        $user ??= auth()->user();
        $this->assertCanAccess($user);

        $year = (int) ($filters['year'] ?? now()->year);

        return $this->cacheService->rememberYearlyEmployeeTargets($this->cacheKey($user, $filters), function () use ($filters, $user, $year) {
            return $this->buildList($filters, $user, $year);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(array $filters, ?User $user = null): array
    {
        $user ??= auth()->user();
        if (! $this->canViewMonitoring($user)) {
            abort(403, 'Yearly target monitoring is only available to managers and admins.');
        }

        $list = $this->list($filters, $user);
        $items = collect($list['items']);
        $withTarget = $items->filter(fn (array $row) => ! empty($row['has_target_record']));
        $statusCounts = $withTarget->countBy(fn (array $row) => $row['status'] ?? 'not_started');

        return [
            'cards' => [
                'employees_with_target' => $withTarget->count(),
                'target_completed' => (int) ($statusCounts['completed'] ?? 0) + (int) ($statusCounts['exceeded'] ?? 0),
                'target_in_progress' => (int) ($statusCounts['in_progress'] ?? 0),
                'target_missed' => (int) ($statusCounts['missed'] ?? 0),
                'no_target_assigned' => (int) $items->filter(fn (array $row) => empty($row['has_target_record']))->count(),
            ],
            'items' => $list['items'],
            'year' => $list['year'],
            'target_working_days' => $this->productivityCalendar->targetWorkingDays((int) $list['year']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function currentYearForEmployee(?User $user = null): array
    {
        $user ??= auth()->user();
        $employeeId = $this->resolveViewerEmployeeId($user);
        $year = (int) now()->year;

        $target = YearlyEmployeeTarget::query()
            ->where('employee_id', $employeeId)
            ->where('target_year', $year)
            ->first();

        if (! $target) {
            return [
                'has_target' => false,
                'target_year' => $year,
                'message' => 'No yearly target has been assigned for '.$year.'.',
            ];
        }

        return [
            'has_target' => true,
            'target_year' => $year,
            'target' => $this->serializeTarget($target, true),
            'can_edit' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function calendar(int $employeeId, int $year, ?User $user = null): array
    {
        $user ??= auth()->user();
        $this->assertCanViewEmployee($user, $employeeId);

        $days = EmployeeCalendarDay::query()
            ->where('employee_id', $employeeId)
            ->whereYear('calendar_date', $year)
            ->orderBy('calendar_date')
            ->get()
            ->map(fn (EmployeeCalendarDay $day) => [
                'date' => $day->calendar_date?->toDateString(),
                'day_type' => $day->day_type,
                'holiday_name' => $day->holiday_name,
                'lead_target' => (int) $day->lead_target,
                'call_target' => (int) $day->call_target,
                'demo_target' => (int) $day->demo_target,
                'followup_target' => (int) $day->followup_target,
            ])
            ->all();

        return [
            'employee_id' => $employeeId,
            'year' => $year,
            'days' => $days,
            'can_edit' => $this->canEdit($user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function calendarSummary(array $filters, ?User $user = null): array
    {
        $user ??= auth()->user();
        $this->assertCanAccess($user);

        $year = (int) ($filters['year'] ?? now()->year);
        $summary = $this->productivityCalendar->buildYearSummary($year);
        $employeeId = ! empty($filters['employee_id']) ? (int) $filters['employee_id'] : null;

        $payload = [
            'calendar_summary' => $summary,
            'holidays' => $this->productivityCalendar->listHolidaysForYear($year),
            'can_edit_holidays' => $this->canEdit($user),
        ];

        if ($employeeId) {
            $this->assertCanViewEmployee($user, $employeeId);
            $target = $this->findByEmployeeAndYear($employeeId, $year);
            $payload['employee_summary'] = $this->productivityCalendar->buildEmployeeSummary(
                $employeeId,
                $year,
                $target,
            );
        }

        return $payload;
    }

    public function recalculate(array $filters, ?User $user = null): void
    {
        $user ??= auth()->user();
        $this->assertCanEdit($user);

        $year = (int) ($filters['year'] ?? now()->year);

        if (! empty($filters['employee_id'])) {
            $employeeId = (int) $filters['employee_id'];
            $this->assertCanManageEmployee($user, $employeeId);
            $target = $this->findByEmployeeAndYear($employeeId, $year);
            if ($target) {
                $this->calendarService->regenerateForTarget($target);
                $this->cacheService->forgetYearlyEmployeeTargets($employeeId);
            }

            return;
        }

        $this->calendarService->regenerateAllEmployeesForYear($year);
        $this->cacheService->forgetYearlyEmployeeTargets();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function store(array $payload, ?User $user = null): array
    {
        $user ??= auth()->user();
        $this->assertCanEdit($user);

        $employeeId = (int) $payload['employee_id'];
        $year = (int) ($payload['target_year'] ?? now()->year);
        $this->assertCanManageEmployee($user, $employeeId);

        if ($this->findByEmployeeAndYear($employeeId, $year)) {
            throw new InvalidArgumentException('A yearly target already exists for this employee. Edit the existing target instead.');
        }

        $target = DB::transaction(function () use ($payload, $user, $employeeId, $year) {
            $target = YearlyEmployeeTarget::query()->create([
                'employee_id' => $employeeId,
                'target_year' => $year,
                'manager_id' => $this->resolveManagerId($user),
                'lead_target' => (int) ($payload['lead_target'] ?? 0),
                'call_target' => (int) ($payload['call_target'] ?? 0),
                'demo_target' => (int) ($payload['demo_target'] ?? 0),
                'followup_target' => (int) ($payload['followup_target'] ?? 0),
                'email_target' => (int) ($payload['email_target'] ?? 0),
                'sms_target' => (int) ($payload['sms_target'] ?? 0),
                'annual_leave_allowance' => (int) ($payload['annual_leave_allowance'] ?? config('yearly_productivity.leave_allowance', 12)),
                'notes' => $payload['notes'] ?? null,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $this->calendarService->regenerateForTarget($target);
            $this->logActivity($target, 'Yearly Target Created', $user);

            return $target;
        });

        $this->cacheService->forgetYearlyEmployeeTargets($employeeId);

        return $this->serializeTarget($target->fresh(['employee:employee_id,name,role']), true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(YearlyEmployeeTarget $target, array $payload, ?User $user = null): array
    {
        $user ??= auth()->user();
        $this->assertCanEdit($user);
        $this->assertCanManageEmployee($user, (int) $target->employee_id);

        DB::transaction(function () use ($target, $payload, $user) {
            $target->fill([
                'lead_target' => (int) ($payload['lead_target'] ?? $target->lead_target),
                'call_target' => (int) ($payload['call_target'] ?? $target->call_target),
                'demo_target' => (int) ($payload['demo_target'] ?? $target->demo_target),
                'followup_target' => (int) ($payload['followup_target'] ?? $target->followup_target),
                'email_target' => (int) ($payload['email_target'] ?? $target->email_target),
                'sms_target' => (int) ($payload['sms_target'] ?? $target->sms_target),
                'annual_leave_allowance' => array_key_exists('annual_leave_allowance', $payload)
                    ? (int) $payload['annual_leave_allowance']
                    : $target->annual_leave_allowance,
                'notes' => array_key_exists('notes', $payload) ? $payload['notes'] : $target->notes,
                'updated_by' => $user->id,
            ])->save();

            $this->calendarService->regenerateForTarget($target->fresh());
            $this->logActivity($target, 'Yearly Target Updated', $user);
        });

        $this->cacheService->forgetYearlyEmployeeTargets((int) $target->employee_id);

        return $this->serializeTarget($target->fresh(['employee:employee_id,name,role']), true);
    }

    public function destroy(YearlyEmployeeTarget $target, ?User $user = null): void
    {
        $user ??= auth()->user();
        $this->assertCanEdit($user);
        $this->assertCanManageEmployee($user, (int) $target->employee_id);

        $employeeId = (int) $target->employee_id;
        DB::transaction(function () use ($target, $user) {
            EmployeeCalendarDay::query()->where('yearly_employee_target_id', $target->id)->delete();
            $this->logActivity($target, 'Yearly Target Deleted', $user);
            $target->delete();
        });

        $this->cacheService->forgetYearlyEmployeeTargets($employeeId);
    }

    public function findByEmployeeAndYear(int $employeeId, int $year): ?YearlyEmployeeTarget
    {
        return YearlyEmployeeTarget::query()
            ->where('employee_id', $employeeId)
            ->where('target_year', $year)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildList(array $filters, User $user, int $year): array
    {
        $targets = $this->scopedTargetQuery($user)
            ->with(['employee:employee_id,name,role'])
            ->where('target_year', $year)
            ->orderBy('employee_id')
            ->get();

        if (! empty($filters['employee_id'])) {
            $employeeId = (int) $filters['employee_id'];
            $this->assertCanViewEmployee($user, $employeeId);
            $targets = $targets->where('employee_id', $employeeId);
        }

        $items = $targets->map(fn (YearlyEmployeeTarget $target) => $this->serializeTarget($target, true))->values()->all();

        if ($this->canViewMonitoring($user)) {
            $items = $this->mergeUnassignedEmployees($items, $year, $user, $filters);
        }

        return [
            'year' => $year,
            'can_edit_capacity' => false,
            'can_edit_targets' => $this->canEdit($user),
            'items' => $items,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function mergeUnassignedEmployees(array $items, int $year, User $user, array $filters): array
    {
        $assignedIds = collect($items)
            ->filter(fn (array $row) => ! empty($row['has_target_record']))
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $employeeQuery = $this->visibleEmployeesQuery($user)->orderBy('name');
        if (! empty($filters['employee_id'])) {
            $employeeQuery->where('employee_id', (int) $filters['employee_id']);
        }

        foreach ($employeeQuery->get(['employee_id', 'name', 'role']) as $employee) {
            if (in_array((int) $employee->employee_id, $assignedIds, true)) {
                continue;
            }

            $items[] = [
                'id' => null,
                'employee_id' => (int) $employee->employee_id,
                'employee_name' => $employee->name,
                'employee_role' => $employee->role,
                'target_year' => $year,
                'has_target_record' => false,
                'target_working_days' => $this->productivityCalendar->targetWorkingDays($year),
                'notes' => null,
                'metrics' => [],
                'overall_pct' => 0,
                'overall_raw_pct' => 0,
                'status' => 'no_target',
                'status_label' => 'No Target Assigned',
                'working_days_elapsed' => 0,
                'working_days_total' => 0,
            ];
        }

        usort($items, fn (array $a, array $b) => strcmp((string) ($a['employee_name'] ?? ''), (string) ($b['employee_name'] ?? '')));

        return $items;
    }

    private function serializeTarget(YearlyEmployeeTarget $target, bool $withProgress = false): array
    {
        $progress = $withProgress ? $this->progressService->buildProgressPayload($target) : null;

        return [
            'id' => $target->id,
            'employee_id' => (int) $target->employee_id,
            'employee_name' => $target->employee?->name,
            'employee_role' => $target->employee?->role,
            'target_year' => (int) $target->target_year,
            'lead_target' => (int) $target->lead_target,
            'call_target' => (int) $target->call_target,
            'demo_target' => (int) $target->demo_target,
            'followup_target' => (int) $target->followup_target,
            'email_target' => (int) $target->email_target,
            'sms_target' => (int) $target->sms_target,
            'notes' => $target->notes,
            'has_target_record' => true,
            'metrics' => $progress['metrics'] ?? [],
            'overall_pct' => $progress['overall_pct'] ?? 0,
            'overall_raw_pct' => $progress['overall_raw_pct'] ?? 0,
            'status' => $progress['status'] ?? 'not_started',
            'status_label' => $progress['status_label'] ?? 'Not Started',
            'working_days_elapsed' => $progress['actual_effective_working_days_elapsed'] ?? $progress['working_days_elapsed'] ?? 0,
            'working_days_total' => $progress['actual_effective_working_days_total'] ?? $progress['working_days_total'] ?? 0,
            'standard_countable_days' => $progress['standard_countable_days'] ?? 0,
            'target_working_days' => $progress['standard_countable_days'] ?? $this->productivityCalendar->targetWorkingDays((int) $target->target_year),
            'standard_non_working_days' => $progress['standard_non_working_days'] ?? $this->productivityCalendar->yearlyNonWorkingDays(),
            'actual_effective_working_days_total' => $progress['actual_effective_working_days_total'] ?? 0,
            'actual_effective_working_days_elapsed' => $progress['actual_effective_working_days_elapsed'] ?? 0,
            'approved_leave_used' => $progress['approved_leave_used'] ?? 0,
            'remaining_leave_balance' => $progress['remaining_leave_balance'] ?? 0,
            'annual_leave_allowance' => $progress['annual_leave_allowance'] ?? (int) config('yearly_productivity.leave_allowance', 12),
            'requires_proration_review' => $progress['requires_proration_review'] ?? false,
            'proration_review_reason' => $progress['proration_review_reason'] ?? null,
            'calendar_summary' => $progress['calendar_summary'] ?? null,
            'achievements' => $progress['achievements'] ?? [],
        ];
    }

    private function scopedTargetQuery(User $user): Builder
    {
        $query = YearlyEmployeeTarget::query();

        if ($this->employeeDataScope->shouldScopeToEmployee($user)) {
            $employeeId = $this->employeeDataScope->scopedEmployeeId($user);
            if (! $employeeId) {
                abort(403, 'No employee profile is linked to this account.');
            }
            $query->where('employee_id', $employeeId);
        } elseif ($this->rbacService->roleKey($user) === 'manager') {
            $ids = $this->visibleEmployeesQuery($user)->pluck('employee_id')->all();
            $query->whereIn('employee_id', $ids ?: [0]);
        }

        return $query;
    }

    private function visibleEmployeesQuery(User $user): Builder
    {
        $query = Employee::query()->whereNull('deleted_at')->where('status', 'Active');

        if ($this->rbacService->roleKey($user) === 'manager') {
            $query->where(function ($q) {
                $q->whereNull('role')
                    ->orWhere('role', 'ilike', '%executive%')
                    ->orWhere('role', 'ilike', '%employee%')
                    ->orWhere('role', 'ilike', '%sales%');
            });
        }

        return $query;
    }

    private function resolveManagerId(User $user): ?int
    {
        return $this->rbacService->roleKey($user) === 'manager'
            ? $this->employeeDataScope->scopedEmployeeId($user)
            : null;
    }

    private function resolveViewerEmployeeId(User $user): int
    {
        if ($this->employeeDataScope->shouldScopeToEmployee($user)) {
            $employeeId = $this->employeeDataScope->scopedEmployeeId($user);
            if (! $employeeId) {
                abort(403, 'No employee profile is linked to this account.');
            }

            return (int) $employeeId;
        }

        abort(403, 'Employee yearly target view is scoped to employees.');
    }

    private function assertCanEdit(User $user): void
    {
        if (! $this->canEdit($user)) {
            abort(403, 'Only managers and admins can manage yearly employee targets.');
        }
    }

    private function assertCanAccess(User $user): void
    {
        $role = $this->rbacService->roleKey($user);
        if (! in_array($role, ['super_admin', 'admin', 'manager', 'employee'], true)) {
            abort(403, 'You do not have access to yearly employee targets.');
        }
    }

    private function assertCanManageEmployee(User $user, int $employeeId): void
    {
        if (in_array($this->rbacService->roleKey($user), ['super_admin', 'admin'], true)) {
            return;
        }

        if (! $this->visibleEmployeesQuery($user)->where('employee_id', $employeeId)->exists()) {
            throw new InvalidArgumentException('You do not have access to manage targets for this employee.');
        }
    }

    private function assertCanViewEmployee(User $user, int $employeeId): void
    {
        if ($this->employeeDataScope->shouldScopeToEmployee($user)) {
            if ((int) $this->employeeDataScope->scopedEmployeeId($user) !== $employeeId) {
                abort(403, 'You can only view your own targets.');
            }

            return;
        }

        if ($this->rbacService->roleKey($user) === 'manager') {
            if (! $this->visibleEmployeesQuery($user)->where('employee_id', $employeeId)->exists()) {
                abort(403, 'You do not have access to this employee.');
            }
        }
    }

    private function logActivity(YearlyEmployeeTarget $target, string $action, User $user): void
    {
        $this->activityLogService->log(
            'LEAD_ASSIGNMENT_ENGINE',
            $action,
            (string) $target->id,
            'Yearly target for employee #'.$target->employee_id.' ('.$target->target_year.')',
            $user->name,
            null,
            null,
            [
                'employee_id' => $target->employee_id,
                'target_year' => $target->target_year,
                'lead_target' => $target->lead_target,
                'call_target' => $target->call_target,
            ],
            Request::ip(),
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function cacheKey(User $user, array $filters): string
    {
        ksort($filters);

        return md5($this->rbacService->roleKey($user).':'.json_encode($filters));
    }
}
