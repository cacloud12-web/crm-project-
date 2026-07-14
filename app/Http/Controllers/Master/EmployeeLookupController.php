<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Presence\EmployeePresenceService;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Lightweight executive list for assignment dropdowns (lead form, assign lead, etc.).
 */
class EmployeeLookupController extends Controller
{
    public function __construct(
        private readonly EmployeeDataScopeService $dataScopeService,
        private readonly EmployeePresenceService $presenceService,
    ) {}

    public function executives(): JsonResponse
    {
        $hasPresence = $this->presenceService->hasLastSeenColumn();

        $query = Employee::query()
            ->with(array_merge(['city'], $this->presenceService->employeeUserWith()))
            ->where('status', 'Active');

        $scopedEmployeeId = $this->dataScopeService->scopedEmployeeId(auth()->user());

        if ($scopedEmployeeId !== null) {
            if ($scopedEmployeeId <= 0) {
                return ApiResponse::success([], 'Employees loaded');
            }

            $query->where('employee_id', $scopedEmployeeId);
        }

        if ($hasPresence) {
            $threshold = $this->presenceService->onlineThreshold()->toDateTimeString();
            $employees = $query
                ->leftJoin('users', 'employees.user_id', '=', 'users.id')
                ->orderByRaw(
                    'CASE WHEN users.last_seen_at IS NOT NULL AND users.last_seen_at >= ? THEN 0 ELSE 1 END',
                    [$threshold]
                )
                ->orderBy('employees.name')
                ->select(
                    'employees.employee_id',
                    'employees.name',
                    'employees.role',
                    'employees.status',
                    'employees.city_id',
                    'employees.user_id'
                )
                ->get();
        } else {
            $employees = $query
                ->orderBy('name')
                ->get(['employee_id', 'name', 'role', 'status', 'city_id', 'user_id']);
        }

        $items = $employees
            ->map(function (Employee $employee) {
                $presence = $this->presenceService->payloadForEmployee($employee);

                return [
                    'employee_id' => $employee->employee_id,
                    'name' => $employee->name,
                    'role' => $employee->role,
                    'status' => $employee->status,
                    'city' => $employee->city?->city_name,
                    'city_name' => $employee->city?->city_name,
                    'is_online' => (bool) ($presence['is_online'] ?? false),
                    'last_seen_at' => $presence['last_seen_at'] ?? null,
                    'last_seen_human' => $presence['last_seen_human'] ?? 'Absent',
                ];
            })
            ->values()
            ->all();

        return ApiResponse::success($items, 'Employees loaded');
    }
}
