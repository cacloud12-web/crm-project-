<?php

namespace App\Http\Controllers;

use App\Services\Assignment\AssignmentCapacityService;
use App\Services\Assignment\AssignmentHeatMapService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class AssignmentDashboardController extends Controller
{
    public function __construct(
        private readonly AssignmentCapacityService $capacityService,
        private readonly AssignmentHeatMapService $heatMapService,
    ) {}

    public function capacity(): JsonResponse
    {
        try {
            return ApiResponse::success(
                $this->capacityService->summary(),
                'Assignment capacity loaded',
            );
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 403);
        }
    }

    public function updateCapacity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'daily_max_capacity' => ['required', 'integer', 'min:1', 'max:500'],
        ]);

        try {
            $summary = $this->capacityService->updateDailyMaxCapacity(
                (int) $validated['daily_max_capacity'],
            );

            return ApiResponse::success($summary, 'Assignment capacity updated');
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 403);
        }
    }

    public function heatMap(Request $request): JsonResponse
    {
        try {
            return ApiResponse::success(
                $this->heatMapService->summary($request->query()),
                'Assignment heat map loaded',
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 403);
        }
    }
}
