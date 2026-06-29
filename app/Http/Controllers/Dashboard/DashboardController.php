<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardService;
use App\Services\Dashboard\EmployeeDashboardService;
use App\Services\Rbac\RbacService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    public function metrics(): JsonResponse
    {
        $user = auth()->user();
        if ($user && app(RbacService::class)->roleKey($user) === 'employee') {
            return response()->json([
                'success' => false,
                'message' => 'Use the employee dashboard endpoint for sales executive accounts.',
            ], 403);
        }

        return ApiResponse::success(
            $this->dashboardService->metrics(),
            'Dashboard metrics loaded',
        );
    }

    public function employeeMetrics(): JsonResponse
    {
        $user = auth()->user();
        if ($user && app(RbacService::class)->roleKey($user) !== 'employee') {
            return response()->json([
                'success' => false,
                'message' => 'Employee dashboard is only available to sales executives.',
            ], 403);
        }

        return ApiResponse::success(
            app(EmployeeDashboardService::class)->metrics(),
            'Employee dashboard loaded',
        );
    }
}
