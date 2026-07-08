<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardDateRange;
use App\Services\Dashboard\DashboardService;
use App\Services\Dashboard\EmployeeDashboardService;
use App\Services\Rbac\RbacService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    public function metrics(Request $request): JsonResponse
    {
        $user = auth()->user();
        if ($user && app(RbacService::class)->roleKey($user) === 'employee') {
            return response()->json([
                'success' => false,
                'message' => 'Use the employee dashboard endpoint for employee accounts.',
            ], 403);
        }

        $validated = $request->validate([
            'employee_id' => 'nullable|integer|min:1',
            'date_preset' => ['nullable', 'string', Rule::in(DashboardDateRange::PRESETS)],
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $employeeId = isset($validated['employee_id']) ? (int) $validated['employee_id'] : null;
        $dateInput = [
            'preset' => $validated['date_preset'] ?? 'today',
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ];

        try {
            return ApiResponse::success(
                $this->dashboardService->metrics($employeeId, $dateInput),
                'Dashboard metrics loaded',
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    public function productivityEmployees(): JsonResponse
    {
        $user = auth()->user();
        if ($user && app(RbacService::class)->roleKey($user) === 'employee') {
            return response()->json([
                'success' => false,
                'message' => 'Employee productivity selector is not available for employees.',
            ], 403);
        }

        return ApiResponse::success(
            $this->dashboardService->productivityEmployees(),
            'Dashboard employees loaded',
        );
    }

    public function employeeMetrics(): JsonResponse
    {
        $user = auth()->user();
        if ($user && app(RbacService::class)->roleKey($user) !== 'employee') {
            return response()->json([
                'success' => false,
                'message' => 'Employee dashboard is only available to employees.',
            ], 403);
        }

        return ApiResponse::success(
            app(EmployeeDashboardService::class)->metrics(),
            'Employee dashboard loaded',
        );
    }
}
