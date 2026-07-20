<?php

namespace App\Services\Assignment;

use App\Models\Employee;
use App\Models\User;
use App\Services\Cache\CrmCacheService;
use App\Services\Dashboard\DashboardDateRange;
use App\Services\Rbac\RbacService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AssignmentHeatMapService
{
    public function __construct(
        private readonly AssignmentCapacityService $capacityService,
        private readonly RbacService $rbacService,
        private readonly CrmCacheService $cacheService,
    ) {}

    public function canView(?User $user = null): bool
    {
        return $this->capacityService->canView($user);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function summary(array $params, ?User $user = null): array
    {
        $user ??= auth()->user();

        if (! $this->canView($user)) {
            abort(403, 'Assignment heat map is only available to managers and admins.');
        }

        $range = DashboardDateRange::resolve(
            $params['period'] ?? 'today',
            $params['from'] ?? null,
            $params['to'] ?? null,
        );

        $employeeId = isset($params['employee_id']) ? (int) $params['employee_id'] : 0;
        $stateId = isset($params['state_id']) ? (int) $params['state_id'] : 0;
        $sourceId = isset($params['source_id']) ? (int) $params['source_id'] : 0;
        $sort = strtolower((string) ($params['sort'] ?? 'highest')) === 'lowest' ? 'asc' : 'desc';

        if ($employeeId > 0 && ! $this->employeeVisibleToViewer($employeeId, $user)) {
            throw new InvalidArgumentException('You do not have access to this employee.');
        }

        $cacheKey = md5(json_encode([
            'period' => $range['preset'],
            'from' => $range['from']->toDateString(),
            'to' => $range['to']->toDateString(),
            'employee_id' => $employeeId,
            'state_id' => $stateId,
            'source_id' => $sourceId,
            'sort' => $sort,
            'scope' => $this->scopeCacheKey($user),
        ]));

        return $this->cacheService->rememberAssignmentHeatMap($cacheKey, function () use (
            $user,
            $range,
            $employeeId,
            $stateId,
            $sourceId,
            $sort,
        ) {
            return $this->buildSummary($user, $range, $employeeId, $stateId, $sourceId, $sort);
        });
    }

    /**
     * @param  array{preset: string, from: \Carbon\Carbon, to: \Carbon\Carbon, label: string}  $range
     * @return array<string, mixed>
     */
    private function buildSummary(
        User $user,
        array $range,
        int $employeeId,
        int $stateId,
        int $sourceId,
        string $sort,
    ): array {
        $visibleEmployeeIds = $this->visibleEmployeeIds($user);

        $query = DB::table('lead_assignment_engines as lae')
            ->join('ca_masters as cm', 'cm.ca_id', '=', 'lae.ca_id')
            ->leftJoin('cities as c', 'c.city_id', '=', 'cm.city_id')
            ->where('lae.status', 'Active')
            ->whereBetween('lae.assigned_date', [
                $range['from']->toDateString(),
                $range['to']->toDateString(),
            ])
            ->whereNull('cm.deleted_at');

        if ($visibleEmployeeIds !== null) {
            $query->whereIn('lae.employee_id', $visibleEmployeeIds);
        }

        if ($employeeId > 0) {
            $query->where('lae.employee_id', $employeeId);
        }

        if ($stateId > 0) {
            $query->where(function ($q) use ($stateId) {
                $q->where('cm.state_id', $stateId)
                    ->orWhere('c.state_id', $stateId);
            });
        }

        if ($sourceId > 0) {
            $query->where('cm.source_id', $sourceId);
        }

        $rows = (clone $query)
            ->selectRaw('COALESCE(c.city_id, 0) as city_id')
            ->selectRaw("COALESCE(NULLIF(TRIM(c.city_name), ''), 'Unknown') as city_name")
            ->selectRaw('MAX(COALESCE(c.state_id, cm.state_id, 0)) as state_id')
            ->selectRaw('COUNT(*) as total_assigned')
            ->groupBy('c.city_id', 'c.city_name')
            ->orderBy('total_assigned', $sort)
            ->get();

        $grandTotal = (int) $rows->sum('total_assigned');

        $cities = $rows->map(function ($row) use ($grandTotal) {
            $total = (int) $row->total_assigned;
            $percentage = $grandTotal > 0 ? round(($total / $grandTotal) * 100, 1) : 0.0;

            return [
                'city_id' => (int) $row->city_id,
                'city' => $row->city_name,
                'state_id' => (int) $row->state_id,
                'total_assigned' => $total,
                'percentage' => $percentage,
            ];
        })->values()->all();

        return [
            'date_range' => [
                'preset' => $range['preset'],
                'from' => $range['from']->toDateString(),
                'to' => $range['to']->toDateString(),
                'label' => $range['label'],
            ],
            'total_assignments' => $grandTotal,
            'sort' => $sort === 'asc' ? 'lowest' : 'highest',
            'cities' => $cities,
            'filter_options' => $this->filterOptions($user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filterOptions(User $user): array
    {
        $visibleIds = $this->visibleEmployeeIds($user);
        $employeeQuery = Employee::query()
            ->whereNull('deleted_at')
            ->where('status', 'Active')
            ->orderBy('name');

        if ($visibleIds !== null) {
            $employeeQuery->whereIn('employee_id', $visibleIds);
        }

        $employees = $employeeQuery->get(['employee_id', 'name']);

        $states = DB::table('states')
            ->orderBy('state_name')
            ->get(['state_id', 'state_name']);

        $sources = DB::table('source_leads')
            ->orderBy('source_name')
            ->get(['source_id', 'source_name']);

        return [
            'employees' => $employees->map(fn ($row) => [
                'employee_id' => (int) $row->employee_id,
                'name' => $row->name,
            ])->values()->all(),
            'states' => $states->map(fn ($row) => [
                'state_id' => (int) $row->state_id,
                'state_name' => $row->state_name,
            ])->values()->all(),
            'sources' => $sources->map(fn ($row) => [
                'source_id' => (int) $row->source_id,
                'source_name' => $row->source_name,
            ])->values()->all(),
        ];
    }

    /**
     * @return list<int>|null Null means all employees (admin/super admin).
     */
    private function visibleEmployeeIds(User $user): ?array
    {
        $role = $this->rbacService->roleKey($user);

        if (in_array($role, ['super_admin', 'admin'], true)) {
            return null;
        }

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

        return $query->pluck('employee_id')->map(fn ($id) => (int) $id)->all();
    }

    private function employeeVisibleToViewer(int $employeeId, User $user): bool
    {
        $visible = $this->visibleEmployeeIds($user);

        return $visible === null || in_array($employeeId, $visible, true);
    }

    private function scopeCacheKey(User $user): string
    {
        $role = $this->rbacService->roleKey($user);

        return 'heatmap:'.$role.':'.(int) $user->id;
    }
}
