<?php

namespace App\Services\Assignment;

use App\Models\DailyEmployeeTarget;
use App\Models\DailyEmployeeTargetAudit;
use App\Models\Employee;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use App\Services\Cache\CrmCacheService;
use App\Services\Notifications\NotificationService;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Services\Rbac\RbacService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use InvalidArgumentException;

class DailyEmployeeTargetService
{
    public function __construct(
        private readonly DailyEmployeeTargetProgressService $progressService,
        private readonly RbacService $rbacService,
        private readonly EmployeeDataScopeService $employeeDataScope,
        private readonly ActivityLogService $activityLogService,
        private readonly NotificationService $notificationService,
        private readonly CrmCacheService $cacheService,
    ) {}

    public function canManage(?User $user = null): bool
    {
        $user ??= auth()->user();

        return in_array($this->rbacService->roleKey($user), ['super_admin', 'admin', 'manager'], true);
    }

    public function canViewMonitoring(?User $user = null): bool
    {
        return $this->canManage($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function list(array $filters, ?User $user = null): array
    {
        $user ??= auth()->user();
        $this->assertCanAccessTargets($user);

        $scopeKey = $this->listCacheKey($user, $filters);

        return $this->cacheService->rememberDailyEmployeeTargets($scopeKey, function () use ($filters, $user) {
            return $this->buildList($filters, $user);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(array $filters, ?User $user = null): array
    {
        $user ??= auth()->user();
        if (! $this->canViewMonitoring($user)) {
            abort(403, 'Daily target monitoring is only available to managers and admins.');
        }

        $scopeKey = 'summary:'.$this->listCacheKey($user, $filters);

        return $this->cacheService->rememberDailyEmployeeTargets($scopeKey, function () use ($filters, $user) {
            return $this->buildSummary($filters, $user);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function history(array $filters, ?User $user = null): array
    {
        $user ??= auth()->user();
        $this->assertCanAccessTargets($user);

        $query = $this->scopedTargetQuery($user)
            ->with([
                'employee:employee_id,name,role',
                'createdByUser:id,name',
            ])
            ->orderByDesc('target_date')
            ->orderByDesc('id');

        $this->applyFilters($query, $filters, $user);

        $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 25)));
        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage);

        return [
            'items' => collect($paginator->items())->map(fn (DailyEmployeeTarget $target) => $this->serializeHistoryRow($target))->values()->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function todayForEmployee(?User $user = null): array
    {
        $user ??= auth()->user();
        $employeeId = $this->resolveViewerEmployeeId($user);
        $today = now()->toDateString();

        $target = DailyEmployeeTarget::query()
            ->where('employee_id', $employeeId)
            ->whereDate('target_date', $today)
            ->first();

        if (! $target) {
            return [
                'has_target' => false,
                'target_date' => $today,
                'message' => 'No target has been assigned for today.',
            ];
        }

        return [
            'has_target' => true,
            'target' => $this->serializeTarget($target, true),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function store(array $payload, ?User $user = null): array
    {
        $user ??= auth()->user();
        $this->assertCanManage($user);

        $employeeId = (int) $payload['employee_id'];
        $this->assertCanManageEmployee($user, $employeeId);

        $targetDate = Carbon::parse($payload['target_date'])->toDateString();
        $existing = $this->findByEmployeeAndDate($employeeId, $targetDate);

        if ($existing) {
            throw new InvalidArgumentException('A target already exists for this employee on this date. Edit the existing target?');
        }

        $target = DB::transaction(function () use ($payload, $user, $employeeId, $targetDate) {
            $target = DailyEmployeeTarget::query()->create([
                'employee_id' => $employeeId,
                'manager_id' => $this->resolveManagerId($user),
                'target_date' => $targetDate,
                'lead_target' => (int) ($payload['lead_target'] ?? 0),
                'call_target' => (int) ($payload['call_target'] ?? 0),
                'demo_target' => (int) ($payload['demo_target'] ?? 0),
                'followup_target' => (int) ($payload['followup_target'] ?? 0),
                'email_target' => (int) ($payload['email_target'] ?? 0),
                'sms_target' => (int) ($payload['sms_target'] ?? 0),
                'notes' => $payload['notes'] ?? null,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $this->recordAudit($target, 'created', null, $this->auditSnapshot($target), $user);
            $this->activityLogService->log(
                'LEAD_ASSIGNMENT_ENGINE',
                'Daily Target Created',
                (string) $target->id,
                'Daily target assigned to employee #'.$employeeId.' for '.$targetDate,
                $user->name,
                null,
                null,
                $this->auditSnapshot($target),
                Request::ip(),
            );

            return $target;
        });

        $this->afterTargetMutation($target, $user, 'assigned');

        return $this->serializeTarget($target->fresh(['employee:employee_id,name,role']), true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(DailyEmployeeTarget $target, array $payload, ?User $user = null): array
    {
        $user ??= auth()->user();
        $this->assertCanManage($user);
        $this->assertCanManageEmployee($user, (int) $target->employee_id);

        $before = $this->auditSnapshot($target);

        DB::transaction(function () use ($target, $payload, $user, $before) {
            $target->fill([
                'lead_target' => (int) ($payload['lead_target'] ?? $target->lead_target),
                'call_target' => (int) ($payload['call_target'] ?? $target->call_target),
                'demo_target' => (int) ($payload['demo_target'] ?? $target->demo_target),
                'followup_target' => (int) ($payload['followup_target'] ?? $target->followup_target),
                'email_target' => (int) ($payload['email_target'] ?? $target->email_target),
                'sms_target' => (int) ($payload['sms_target'] ?? $target->sms_target),
                'notes' => array_key_exists('notes', $payload) ? $payload['notes'] : $target->notes,
                'updated_by' => $user->id,
            ]);

            if (isset($payload['target_date'])) {
                $newDate = Carbon::parse($payload['target_date'])->toDateString();
                if ($newDate !== $target->target_date->toDateString()) {
                    $duplicate = $this->findByEmployeeAndDate((int) $target->employee_id, $newDate, (int) $target->id);
                    if ($duplicate) {
                        throw new InvalidArgumentException('A target already exists for this employee on this date.');
                    }
                    $target->target_date = $newDate;
                }
            }

            $target->save();

            $this->recordAudit($target, 'updated', $before, $this->auditSnapshot($target), $user);
            $this->activityLogService->log(
                'LEAD_ASSIGNMENT_ENGINE',
                'Daily Target Updated',
                (string) $target->id,
                'Daily target updated for employee #'.$target->employee_id,
                $user->name,
                null,
                $before,
                $this->auditSnapshot($target),
                Request::ip(),
            );
        });

        $target->refresh();
        $this->afterTargetMutation($target, $user, 'updated');

        return $this->serializeTarget($target->loadMissing('employee:employee_id,name,role'), true);
    }

    public function destroy(DailyEmployeeTarget $target, ?User $user = null): void
    {
        $user ??= auth()->user();
        $this->assertCanManage($user);
        $this->assertCanManageEmployee($user, (int) $target->employee_id);

        $before = $this->auditSnapshot($target);
        $employeeId = (int) $target->employee_id;

        DB::transaction(function () use ($target, $user, $before) {
            $this->recordAudit($target, 'deleted', $before, null, $user);
            $this->activityLogService->log(
                'LEAD_ASSIGNMENT_ENGINE',
                'Daily Target Deleted',
                (string) $target->id,
                'Daily target deleted for employee #'.$target->employee_id,
                $user->name,
                null,
                $before,
                null,
                Request::ip(),
            );
            $target->delete();
        });

        $this->cacheService->forgetDailyEmployeeTargets($employeeId);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function copyYesterday(array $options, ?User $user = null): array
    {
        $user ??= auth()->user();
        $this->assertCanManage($user);

        $sourceDate = Carbon::parse($options['source_date'] ?? now()->subDay()->toDateString())->toDateString();
        $targetDate = Carbon::parse($options['target_date'] ?? now()->toDateString())->toDateString();
        $employeeIds = $this->resolveCopyEmployeeIds($options, $user);

        return $this->copyTargets($employeeIds, $sourceDate, $targetDate, (bool) ($options['overwrite'] ?? false), $user);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function copyToEmployees(array $options, ?User $user = null): array
    {
        $user ??= auth()->user();
        $this->assertCanManage($user);

        $sourceTargetId = (int) ($options['source_target_id'] ?? 0);
        $source = DailyEmployeeTarget::query()->findOrFail($sourceTargetId);
        $this->assertCanManageEmployee($user, (int) $source->employee_id);

        $employeeIds = array_map('intval', $options['employee_ids'] ?? []);
        $targetDate = Carbon::parse($options['target_date'] ?? $source->target_date)->toDateString();

        return $this->copyFromTemplate($source, $employeeIds, $targetDate, (bool) ($options['overwrite'] ?? false), $user);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function copyToTeam(array $options, ?User $user = null): array
    {
        $user ??= auth()->user();
        $this->assertCanManage($user);

        $sourceTargetId = (int) ($options['source_target_id'] ?? 0);
        $source = DailyEmployeeTarget::query()->findOrFail($sourceTargetId);
        $this->assertCanManageEmployee($user, (int) $source->employee_id);

        $employeeIds = $this->visibleEmployeesQuery($user)
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $targetDate = Carbon::parse($options['target_date'] ?? $source->target_date)->toDateString();

        return $this->copyFromTemplate($source, $employeeIds, $targetDate, (bool) ($options['overwrite'] ?? false), $user);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function copyWeekdays(array $options, ?User $user = null): array
    {
        $user ??= auth()->user();
        $this->assertCanManage($user);

        $sourceTargetId = (int) ($options['source_target_id'] ?? 0);
        $source = DailyEmployeeTarget::query()->findOrFail($sourceTargetId);
        $this->assertCanManageEmployee($user, (int) $source->employee_id);

        $start = Carbon::parse($options['start_date'] ?? now()->toDateString());
        $days = min(14, max(1, (int) ($options['days'] ?? 5)));
        $employeeIds = [(int) $source->employee_id];
        $created = 0;
        $skipped = 0;

        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i);
            if ($date->isWeekend()) {
                continue;
            }
            $result = $this->copyFromTemplate($source, $employeeIds, $date->toDateString(), (bool) ($options['overwrite'] ?? false), $user);
            $created += (int) ($result['created'] ?? 0);
            $skipped += (int) ($result['skipped'] ?? 0);
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    public function findByEmployeeAndDate(int $employeeId, string $date, ?int $exceptId = null): ?DailyEmployeeTarget
    {
        $query = DailyEmployeeTarget::query()
            ->where('employee_id', $employeeId)
            ->whereDate('target_date', $date);

        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildList(array $filters, User $user): array
    {
        $dateRange = $this->resolveDateRange($filters);
        $query = $this->scopedTargetQuery($user)
            ->with(['employee:employee_id,name,role', 'manager:employee_id,name'])
            ->whereBetween('target_date', [$dateRange['from'], $dateRange['to']])
            ->orderByDesc('target_date')
            ->orderBy('employee_id');

        $this->applyFilters($query, $filters, $user);

        $targets = $query->get();
        $items = $targets->map(fn (DailyEmployeeTarget $target) => $this->serializeTarget($target, true))->values()->all();

        if ($this->canViewMonitoring($user) && ($filters['include_unassigned'] ?? true)) {
            $items = $this->mergeUnassignedEmployees($items, $dateRange['focus_date'], $user, $filters);
        }

        return [
            'date_range' => $dateRange,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSummary(array $filters, User $user): array
    {
        $list = $this->buildList($filters, $user);
        $items = collect($list['items']);
        $withTarget = $items->filter(fn (array $row) => ! empty($row['has_target_record']));
        $statusCounts = $withTarget->countBy(fn (array $row) => $row['status'] ?? 'not_started');

        $completed = (int) ($statusCounts['completed'] ?? 0) + (int) ($statusCounts['exceeded'] ?? 0);
        $inProgress = (int) ($statusCounts['in_progress'] ?? 0);
        $missed = (int) ($statusCounts['missed'] ?? 0);
        $noTarget = (int) $items->filter(fn (array $row) => empty($row['has_target_record']))->count();

        $insights = [
            'completed_employees' => $withTarget->filter(fn (array $row) => in_array($row['status'], ['completed', 'exceeded'], true))->values()->all(),
            'below_50_pct' => $withTarget->filter(fn (array $row) => ($row['overall_raw_pct'] ?? 0) > 0 && ($row['overall_raw_pct'] ?? 0) < 50)->values()->all(),
            'no_activity' => $withTarget->filter(fn (array $row) => ($row['overall_raw_pct'] ?? 0) <= 0)->values()->all(),
            'missed_target' => $withTarget->filter(fn (array $row) => ($row['status'] ?? '') === 'missed')->values()->all(),
            'top_performer' => $withTarget->sortByDesc('overall_raw_pct')->first(),
            'highest_demo' => $this->topMetricPerformer($withTarget, 'demo'),
            'highest_call' => $this->topMetricPerformer($withTarget, 'call'),
            'highest_lead' => $this->topMetricPerformer($withTarget, 'lead'),
        ];

        return [
            'cards' => [
                'employees_with_target' => $withTarget->count(),
                'target_completed' => $completed,
                'target_in_progress' => $inProgress,
                'target_missed' => $missed,
                'no_target_assigned' => $noTarget,
            ],
            'insights' => $insights,
            'items' => $list['items'],
            'date_range' => $list['date_range'],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<string, mixed>|null
     */
    private function topMetricPerformer(Collection $items, string $metricKey): ?array
    {
        return $items
            ->sortByDesc(function (array $row) use ($metricKey) {
                foreach ($row['metrics'] ?? [] as $metric) {
                    if (($metric['key'] ?? '') === $metricKey) {
                        return (float) ($metric['raw_pct'] ?? 0);
                    }
                }

                return 0;
            })
            ->first();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function mergeUnassignedEmployees(array $items, string $focusDate, User $user, array $filters): array
    {
        $assignedIds = collect($items)
            ->filter(fn (array $row) => ($row['target_date'] ?? '') === $focusDate && ! empty($row['has_target_record']))
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $employeeQuery = $this->visibleEmployeesQuery($user)->orderBy('name');
        if (! empty($filters['employee_id'])) {
            $employeeQuery->where('employee_id', (int) $filters['employee_id']);
        }

        $employees = $employeeQuery->get(['employee_id', 'name', 'role']);
        foreach ($employees as $employee) {
            if (in_array((int) $employee->employee_id, $assignedIds, true)) {
                continue;
            }
            $items[] = [
                'id' => null,
                'employee_id' => (int) $employee->employee_id,
                'employee_name' => $employee->name,
                'employee_role' => $employee->role,
                'target_date' => $focusDate,
                'has_target_record' => false,
                'notes' => null,
                'metrics' => [],
                'overall_pct' => 0,
                'overall_raw_pct' => 0,
                'status' => 'no_target',
                'status_label' => 'No Target Assigned',
            ];
        }

        usort($items, fn (array $a, array $b) => strcmp((string) ($a['employee_name'] ?? ''), (string) ($b['employee_name'] ?? '')));

        return $items;
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array<string, mixed>
     */
    private function copyTargets(array $employeeIds, string $sourceDate, string $targetDate, bool $overwrite, User $user): array
    {
        $sources = DailyEmployeeTarget::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('target_date', $sourceDate)
            ->get()
            ->keyBy('employee_id');

        $created = 0;
        $skipped = 0;

        foreach ($employeeIds as $employeeId) {
            $source = $sources->get($employeeId);
            if (! $source) {
                $skipped++;
                continue;
            }
            $result = $this->copyFromTemplate($source, [$employeeId], $targetDate, $overwrite, $user);
            $created += (int) ($result['created'] ?? 0);
            $skipped += (int) ($result['skipped'] ?? 0);
        }

        return compact('created', 'skipped');
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array<string, mixed>
     */
    private function copyFromTemplate(DailyEmployeeTarget $source, array $employeeIds, string $targetDate, bool $overwrite, User $user): array
    {
        $created = 0;
        $skipped = 0;

        foreach ($employeeIds as $employeeId) {
            $this->assertCanManageEmployee($user, $employeeId);
            $existing = $this->findByEmployeeAndDate($employeeId, $targetDate);
            if ($existing && ! $overwrite) {
                $skipped++;
                continue;
            }

            $payload = [
                'employee_id' => $employeeId,
                'target_date' => $targetDate,
                'lead_target' => $source->lead_target,
                'call_target' => $source->call_target,
                'demo_target' => $source->demo_target,
                'followup_target' => $source->followup_target,
                'email_target' => $source->email_target,
                'sms_target' => $source->sms_target,
                'notes' => $source->notes,
            ];

            if ($existing) {
                $this->update($existing, $payload, $user);
            } else {
                $this->store($payload, $user);
            }
            $created++;
        }

        return compact('created', 'skipped');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<int>
     */
    private function resolveCopyEmployeeIds(array $options, User $user): array
    {
        if (! empty($options['employee_ids'])) {
            return array_map('intval', $options['employee_ids']);
        }

        return $this->visibleEmployeesQuery($user)
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function scopedTargetQuery(User $user): Builder
    {
        $query = DailyEmployeeTarget::query();

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
        $query = Employee::query()
            ->whereNull('deleted_at')
            ->where('status', 'Active');

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

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters, User $user): void
    {
        if (! empty($filters['employee_id'])) {
            $employeeId = (int) $filters['employee_id'];
            $this->assertCanViewEmployee($user, $employeeId);
            $query->where('employee_id', $employeeId);
        }

        if (! empty($filters['manager_id']) && $this->canViewMonitoring($user)) {
            $query->where('manager_id', (int) $filters['manager_id']);
        }

        if (! empty($filters['status'])) {
            // Status is computed; filter post-fetch in controller if needed.
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{from: string, to: string, focus_date: string, preset: string}
     */
    private function resolveDateRange(array $filters): array
    {
        $preset = (string) ($filters['preset'] ?? 'today');
        $today = now()->startOfDay();

        return match ($preset) {
            'yesterday' => [
                'preset' => $preset,
                'from' => $today->copy()->subDay()->toDateString(),
                'to' => $today->copy()->subDay()->toDateString(),
                'focus_date' => $today->copy()->subDay()->toDateString(),
            ],
            'this_week' => [
                'preset' => $preset,
                'from' => $today->copy()->startOfWeek()->toDateString(),
                'to' => $today->copy()->endOfWeek()->toDateString(),
                'focus_date' => $today->toDateString(),
            ],
            'custom' => [
                'preset' => $preset,
                'from' => Carbon::parse($filters['from'] ?? $today)->toDateString(),
                'to' => Carbon::parse($filters['to'] ?? $filters['from'] ?? $today)->toDateString(),
                'focus_date' => Carbon::parse($filters['from'] ?? $today)->toDateString(),
            ],
            default => [
                'preset' => 'today',
                'from' => $today->toDateString(),
                'to' => $today->toDateString(),
                'focus_date' => $today->toDateString(),
            ],
        };
    }

    private function serializeTarget(DailyEmployeeTarget $target, bool $withProgress = false): array
    {
        $progress = $withProgress ? $this->progressService->buildProgressPayload($target) : null;

        return [
            'id' => $target->id,
            'employee_id' => (int) $target->employee_id,
            'employee_name' => $target->employee?->name,
            'employee_role' => $target->employee?->role,
            'manager_id' => $target->manager_id ? (int) $target->manager_id : null,
            'manager_name' => $target->manager?->name,
            'target_date' => $target->target_date?->toDateString(),
            'target_date_label' => $target->target_date?->format('d M Y'),
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
            'achievements' => $progress['achievements'] ?? [],
        ];
    }

    private function serializeHistoryRow(DailyEmployeeTarget $target): array
    {
        $row = $this->serializeTarget($target, true);
        $row['assigned_by'] = $target->createdByUser?->name;

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(DailyEmployeeTarget $target): array
    {
        return [
            'employee_id' => (int) $target->employee_id,
            'target_date' => $target->target_date?->toDateString(),
            'lead_target' => (int) $target->lead_target,
            'call_target' => (int) $target->call_target,
            'demo_target' => (int) $target->demo_target,
            'followup_target' => (int) $target->followup_target,
            'email_target' => (int) $target->email_target,
            'sms_target' => (int) $target->sms_target,
            'notes' => $target->notes,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function recordAudit(DailyEmployeeTarget $target, string $action, ?array $before, ?array $after, User $user): void
    {
        DailyEmployeeTargetAudit::query()->create([
            'daily_employee_target_id' => $target->id,
            'employee_id' => $target->employee_id,
            'target_date' => $target->target_date,
            'action' => $action,
            'before_values' => $before,
            'after_values' => $after,
            'changed_by' => $user->id,
            'ip_address' => Request::ip(),
            'created_at' => now(),
        ]);
    }

    private function afterTargetMutation(DailyEmployeeTarget $target, User $user, string $event): void
    {
        $this->cacheService->forgetDailyEmployeeTargets((int) $target->employee_id);

        $employee = Employee::query()->with('user:id,name,email')->find($target->employee_id);
        $userId = $employee?->user_id;
        if (! $userId) {
            return;
        }

        $dateLabel = $target->target_date?->format('d M Y') ?? now()->format('d M Y');
        $message = $event === 'updated'
            ? "Your daily target for {$dateLabel} has been updated."
            : "Your daily target for {$dateLabel} has been assigned.";

        $this->notificationService->notifyUser(
            (int) $userId,
            'daily_target',
            'Daily Target',
            $message,
            [
                'entity_type' => 'daily_employee_target',
                'entity_id' => (string) $target->id,
                'target_date' => $target->target_date?->toDateString(),
            ],
        );
    }

    private function resolveManagerId(User $user): ?int
    {
        if ($this->rbacService->roleKey($user) !== 'manager') {
            return null;
        }

        return $this->employeeDataScope->scopedEmployeeId($user);
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

        abort(403, 'Employee target view is scoped to employees.');
    }

    private function assertCanManage(User $user): void
    {
        if (! $this->canManage($user)) {
            abort(403, 'Only managers and admins can manage daily employee targets.');
        }
    }

    private function assertCanAccessTargets(User $user): void
    {
        $role = $this->rbacService->roleKey($user);
        if (! in_array($role, ['super_admin', 'admin', 'manager', 'employee'], true)) {
            abort(403, 'You do not have access to daily employee targets.');
        }
    }

    private function assertCanManageEmployee(User $user, int $employeeId): void
    {
        if (in_array($this->rbacService->roleKey($user), ['super_admin', 'admin'], true)) {
            return;
        }

        $allowed = $this->visibleEmployeesQuery($user)
            ->where('employee_id', $employeeId)
            ->exists();

        if (! $allowed) {
            throw new InvalidArgumentException('You do not have access to manage targets for this employee.');
        }
    }

    private function assertCanViewEmployee(User $user, int $employeeId): void
    {
        if ($this->employeeDataScope->shouldScopeToEmployee($user)) {
            $scoped = $this->employeeDataScope->scopedEmployeeId($user);
            if ((int) $scoped !== $employeeId) {
                abort(403, 'You can only view your own targets.');
            }

            return;
        }

        if ($this->rbacService->roleKey($user) === 'manager') {
            $allowed = $this->visibleEmployeesQuery($user)
                ->where('employee_id', $employeeId)
                ->exists();
            if (! $allowed) {
                abort(403, 'You do not have access to this employee.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function listCacheKey(User $user, array $filters): string
    {
        ksort($filters);

        return md5($this->rbacService->roleKey($user).':'.json_encode($filters));
    }
}
