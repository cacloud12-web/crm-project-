<?php

namespace App\Http\Controllers\Assignment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assignment\CopyDailyEmployeeTargetRequest;
use App\Http\Requests\Assignment\StoreDailyEmployeeTargetRequest;
use App\Http\Requests\Assignment\UpdateDailyEmployeeTargetRequest;
use App\Models\DailyEmployeeTarget;
use App\Services\Assignment\DailyEmployeeTargetService;
use App\Support\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class DailyEmployeeTargetController extends Controller
{
    public function __construct(
        private readonly DailyEmployeeTargetService $targetService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            return ApiResponse::success(
                $this->targetService->list($request->query()),
                'Daily employee targets loaded',
            );
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 403);
        }
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            return ApiResponse::success(
                $this->targetService->summary($request->query()),
                'Daily target summary loaded',
            );
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 403);
        }
    }

    public function history(Request $request): JsonResponse
    {
        try {
            return ApiResponse::success(
                $this->targetService->history($request->query()),
                'Daily target history loaded',
            );
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 403);
        }
    }

    public function today(): JsonResponse
    {
        try {
            return ApiResponse::success(
                $this->targetService->todayForEmployee(),
                'Today\'s target loaded',
            );
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 403);
        }
    }

    public function store(StoreDailyEmployeeTargetRequest $request): JsonResponse
    {
        try {
            return ApiResponse::success(
                $this->targetService->store($request->validated()),
                'Daily target assigned',
                201,
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        } catch (QueryException $e) {
            return ApiResponse::error('A target already exists for this employee on this date.', 409);
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 403);
        }
    }

    public function update(UpdateDailyEmployeeTargetRequest $request, DailyEmployeeTarget $dailyEmployeeTarget): JsonResponse
    {
        try {
            return ApiResponse::success(
                $this->targetService->update($dailyEmployeeTarget, $request->validated()),
                'Daily target updated',
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 403);
        }
    }

    public function destroy(DailyEmployeeTarget $dailyEmployeeTarget): JsonResponse
    {
        try {
            $this->targetService->destroy($dailyEmployeeTarget);

            return ApiResponse::success(null, 'Daily target deleted');
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 403);
        }
    }

    public function copyYesterday(CopyDailyEmployeeTargetRequest $request): JsonResponse
    {
        return $this->copyResponse(fn () => $this->targetService->copyYesterday($request->validated()));
    }

    public function copyToEmployees(CopyDailyEmployeeTargetRequest $request): JsonResponse
    {
        return $this->copyResponse(fn () => $this->targetService->copyToEmployees($request->validated()));
    }

    public function copyToTeam(CopyDailyEmployeeTargetRequest $request): JsonResponse
    {
        return $this->copyResponse(fn () => $this->targetService->copyToTeam($request->validated()));
    }

    public function copyWeekdays(CopyDailyEmployeeTargetRequest $request): JsonResponse
    {
        return $this->copyResponse(fn () => $this->targetService->copyWeekdays($request->validated()));
    }

    private function copyResponse(callable $callback): JsonResponse
    {
        try {
            return ApiResponse::success($callback(), 'Daily targets copied');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 403);
        }
    }
}
