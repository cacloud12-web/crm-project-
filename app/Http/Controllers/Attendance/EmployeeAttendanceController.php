<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\BulkMarkAttendanceRequest;
use App\Http\Requests\Attendance\MarkAttendanceRequest;
use App\Services\Attendance\EmployeeAttendanceService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class EmployeeAttendanceController extends Controller
{
    public function __construct(
        private readonly EmployeeAttendanceService $attendanceService,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        try {
            $data = $this->attendanceService->summary(
                $request->user(),
                $request->query('date'),
            );
        } catch (AccessDeniedHttpException $e) {
            return ApiResponse::error($e->getMessage(), 403);
        } catch (ValidationException $e) {
            return ApiResponse::error($e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('Unable to load attendance summary.', 500);
        }

        return ApiResponse::success($data, 'Attendance summary loaded');
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $data = $this->attendanceService->list($request->user(), $request->query());
        } catch (AccessDeniedHttpException $e) {
            return ApiResponse::error($e->getMessage(), 403);
        } catch (ValidationException $e) {
            return ApiResponse::error($e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('Unable to load attendance list.', 500);
        }

        return ApiResponse::success($data, 'Attendance list loaded');
    }

    public function store(MarkAttendanceRequest $request): JsonResponse
    {
        try {
            $data = $this->attendanceService->mark(
                $request->user(),
                (int) $request->validated('employee_id'),
                (string) $request->validated('status'),
                $request->validated('date'),
                $request->validated('remarks'),
            );
        } catch (AccessDeniedHttpException $e) {
            return ApiResponse::error($e->getMessage(), 403);
        } catch (NotFoundHttpException $e) {
            return ApiResponse::error($e->getMessage(), 404);
        } catch (ValidationException $e) {
            return ApiResponse::error($e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('Unable to save attendance.', 500);
        }

        return ApiResponse::success($data, 'Attendance saved');
    }

    public function bulkStore(BulkMarkAttendanceRequest $request): JsonResponse
    {
        try {
            $data = $this->attendanceService->bulkMark(
                $request->user(),
                $request->validated('employee_ids'),
                (string) $request->validated('status'),
                $request->validated('date'),
            );
        } catch (AccessDeniedHttpException $e) {
            return ApiResponse::error($e->getMessage(), 403);
        } catch (ValidationException $e) {
            return ApiResponse::error($e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('Unable to save attendance.', 500);
        }

        return ApiResponse::success($data, 'Attendance updated');
    }
}
