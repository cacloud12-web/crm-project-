<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Employee;
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
    ) {}

    public function executives(): JsonResponse
    {
        $query = Employee::query()
            ->with(['city'])
            ->where('status', 'Active');

        $scopedEmployeeId = $this->dataScopeService->scopedEmployeeId(auth()->user());

        if ($scopedEmployeeId !== null) {
            if ($scopedEmployeeId <= 0) {
                return ApiResponse::success([], 'Employees loaded');
            }

            $query->where('employee_id', $scopedEmployeeId);
        }

        $employees = $query
            ->orderBy('name')
            ->get(['employee_id', 'name', 'role', 'status', 'city_id', 'user_id']);

        $items = $employees
            ->map(function (Employee $employee) {
                return [
                    'employee_id' => $employee->employee_id,
                    'name' => $employee->name,
                    'role' => $employee->role,
                    'status' => $employee->status,
                    'city' => $employee->city?->city_name,
                    'city_name' => $employee->city?->city_name,
                ];
            })
            ->values()
            ->all();

        return ApiResponse::success($items, 'Employees loaded');
    }
}
