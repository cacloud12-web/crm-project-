<?php

namespace App\Services\Assignment;

use App\Models\Employee;
use App\Models\LeadAssignmentEngine;
use App\Models\User;
use App\Services\Cache\CrmCacheService;
use App\Services\Rbac\RbacService;
use App\Services\Settings\AssignmentCapacitySettings;
use App\Services\Settings\CrmSettingsService;
use App\Support\Database\SqlAggregate;

class AssignmentCapacityService
{
    public function __construct(
        private readonly AssignmentCapacitySettings $capacitySettings,
        private readonly RbacService $rbacService,
        private readonly CrmCacheService $cacheService,
    ) {}

    public function canView(?User $user = null): bool
    {
        $user ??= auth()->user();

        return in_array($this->rbacService->roleKey($user), ['super_admin', 'admin', 'manager'], true);
    }

    public function canEdit(?User $user = null): bool
    {
        $user ??= auth()->user();

        return in_array($this->rbacService->roleKey($user), ['super_admin', 'manager'], true);
    }

    /**
     * @return array{max_daily_capacity: int, employees: list<array<string, mixed>>, can_edit_capacity: bool, date: string}
     */
    public function updateDailyMaxCapacity(int $maxCapacity, ?User $user = null): array
    {
        $user ??= auth()->user();

        if (! $this->canEdit($user)) {
            abort(403, 'Only managers and super admins can change assignment capacity.');
        }

        if ($maxCapacity < 1 || $maxCapacity > 500) {
            throw new \InvalidArgumentException('Daily assignment capacity must be between 1 and 500.');
        }

        app(CrmSettingsService::class)->save(
            ['assignment' => ['daily_max_capacity' => $maxCapacity]],
            $user->name ?? $user->email ?? 'System',
        );

        $this->cacheService->forgetAssignmentWidgets();

        return $this->buildSummary($user);
    }

    /**
     * @return array{max_daily_capacity: int, employees: list<array<string, mixed>>}
     */
    public function summary(?User $user = null): array
    {
        $user ??= auth()->user();

        if (! $this->canView($user)) {
            abort(403, 'Assignment capacity is only available to managers and admins.');
        }

        $scopeKey = $this->scopeCacheKey($user);

        return $this->cacheService->rememberAssignmentCapacity($scopeKey, function () use ($user) {
            return $this->buildSummary($user);
        });
    }

    /**
     * @return array<int, int>
     */
    public function assignedTodayCounts(array $employeeIds, ?string $date = null): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $date ??= now()->toDateString();

        return LeadAssignmentEngine::query()
            ->selectRaw('employee_id')
            ->selectRaw(SqlAggregate::countFilter('*', 'assigned_date = ?').' as assigned_today', [$date])
            ->whereIn('employee_id', $employeeIds)
            ->groupBy('employee_id')
            ->pluck('assigned_today', 'employee_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * @return list<int>
     */
    public function autoAssignableEmployeeIds(array $employeeIds, ?string $date = null): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $maxCapacity = $this->capacitySettings->dailyMaxCapacity();
        $todayCounts = $this->assignedTodayCounts($employeeIds, $date);

        return array_values(array_filter(
            $employeeIds,
            fn (int $employeeId) => ($todayCounts[$employeeId] ?? 0) < $maxCapacity,
        ));
    }

    public function isAtFullCapacity(int $employeeId, ?string $date = null): bool
    {
        $maxCapacity = $this->capacitySettings->dailyMaxCapacity();
        $counts = $this->assignedTodayCounts([$employeeId], $date);

        return ($counts[$employeeId] ?? 0) >= $maxCapacity;
    }

    /**
     * @return array{max_daily_capacity: int, employees: list<array<string, mixed>>}
     */
    private function buildSummary(User $user): array
    {
        $maxCapacity = $this->capacitySettings->dailyMaxCapacity();
        $today = now()->toDateString();

        $employees = $this->visibleEmployeesQuery($user)
            ->with('city:city_id,city_name')
            ->orderBy('name')
            ->get(['employee_id', 'name', 'role', 'status', 'city_id']);

        $employeeIds = $employees->pluck('employee_id')->map(fn ($id) => (int) $id)->all();
        $todayCounts = $this->assignedTodayCounts($employeeIds, $today);

        $items = $employees->map(function (Employee $employee) use ($todayCounts, $maxCapacity) {
            $assignedToday = (int) ($todayCounts[(int) $employee->employee_id] ?? 0);
            $percentage = AssignmentCapacitySettings::capacityPercentage($assignedToday, $maxCapacity);
            $remaining = max(0, $maxCapacity - $assignedToday);
            $atFullCapacity = $assignedToday >= $maxCapacity;

            return [
                'employee_id' => (int) $employee->employee_id,
                'name' => $employee->name,
                'role' => $employee->role,
                'city' => $employee->city?->city_name,
                'status' => $employee->status,
                'assigned_today' => $assignedToday,
                'max_daily_capacity' => $maxCapacity,
                'remaining_capacity' => $remaining,
                'percentage' => $percentage,
                'capacity_tier' => AssignmentCapacitySettings::capacityTier($percentage),
                'at_full_capacity' => $atFullCapacity,
                'full_capacity_label' => $atFullCapacity ? 'Full Capacity' : null,
                'tooltip' => $atFullCapacity ? 'Daily assignment limit reached.' : null,
                'auto_assignable' => ! $atFullCapacity,
            ];
        })->values()->all();

        return [
            'date' => $today,
            'max_daily_capacity' => $maxCapacity,
            'can_edit_capacity' => $this->canEdit($user),
            'employees' => $items,
        ];
    }

    private function visibleEmployeesQuery(User $user)
    {
        $role = $this->rbacService->roleKey($user);
        $query = Employee::query()
            ->whereNull('deleted_at')
            ->where('status', 'Active');

        if ($role === 'manager') {
            $query->where(function ($q) {
                $q->whereNull('role')
                    ->orWhere('role', 'ilike', '%executive%')
                    ->orWhere('role', 'ilike', '%employee%')
                    ->orWhere('role', 'ilike', '%sales%');
            });
        }

        return $query;
    }

    private function scopeCacheKey(User $user): string
    {
        $role = $this->rbacService->roleKey($user);

        return 'capacity:'.$role.':'.(int) $user->id;
    }
}
