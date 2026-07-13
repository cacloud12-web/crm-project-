<?php

namespace App\Http\Controllers\Assignment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assignment\StoreYearlyEmployeeTargetRequest;
use App\Http\Requests\Assignment\UpdateYearlyEmployeeTargetRequest;
use App\Models\EmployeeLeave;
use App\Models\YearlyEmployeeTarget;
use App\Services\Assignment\CompanyHolidayService;
use App\Services\Assignment\EmployeeLeaveService;
use App\Services\Assignment\YearProductivityCalendarService;
use App\Services\Assignment\YearlyEmployeeTargetService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class YearlyEmployeeTargetController extends Controller
{
    public function __construct(
        private readonly YearlyEmployeeTargetService $targetService,
        private readonly CompanyHolidayService $holidayService,
        private readonly YearProductivityCalendarService $productivityCalendar,
        private readonly EmployeeLeaveService $leaveService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            return ApiResponse::success(
                $this->targetService->list($request->query()),
                'Yearly employee targets loaded',
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
                'Yearly target summary loaded',
            );
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 403);
        }
    }

    public function calendarSummary(Request $request): JsonResponse
    {
        try {
            return ApiResponse::success(
                $this->targetService->calendarSummary($request->query()),
                'Yearly productivity calendar summary loaded',
            );
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 403);
        }
    }

    public function recalculate(Request $request): JsonResponse
    {
        try {
            $this->targetService->recalculate($request->query());

            return ApiResponse::success(null, 'Yearly calendars recalculated');
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 403);
        }
    }

    public function currentYear(): JsonResponse
    {
        try {
            return ApiResponse::success(
                $this->targetService->currentYearForEmployee(),
                'Current yearly target loaded',
            );
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 403);
        }
    }

    public function calendar(Request $request, int $employeeId): JsonResponse
    {
        try {
            $year = (int) $request->query('year', now()->year);

            return ApiResponse::success(
                $this->targetService->calendar($employeeId, $year),
                'Employee calendar loaded',
            );
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 403);
        }
    }

    public function holidays(Request $request): JsonResponse
    {
        $year = (int) $request->query('year', now()->year);

        return ApiResponse::success([
            'year' => $year,
            'items' => $this->productivityCalendar->listHolidaysForYear($year),
            'calendar_summary' => $this->productivityCalendar->buildYearSummary($year),
            'can_edit' => $this->holidayService->canEdit(),
        ], 'Company holidays loaded');
    }

    public function syncHolidayDates(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'year' => ['required', 'integer', 'min:2020', 'max:2100'],
                'holidays' => ['required', 'array'],
                'holidays.*.id' => ['required', 'integer'],
                'holidays.*.holiday_date' => ['required', 'date'],
            ]);

            return ApiResponse::success([
                'year' => (int) $validated['year'],
                'items' => $this->productivityCalendar->syncHolidayDatesForYear(
                    (int) $validated['year'],
                    $validated['holidays'],
                ),
            ], 'Company holiday dates updated');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    public function syncHolidays(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'holidays' => ['required', 'array'],
                'holidays.*.id' => ['nullable', 'integer'],
                'holidays.*.name' => ['required', 'string', 'max:120'],
                'holidays.*.month' => ['required', 'integer', 'min:1', 'max:12'],
                'holidays.*.day' => ['required', 'integer', 'min:1', 'max:31'],
                'holidays.*.is_active' => ['nullable', 'boolean'],
            ]);

            return ApiResponse::success(
                ['items' => $this->holidayService->syncAll($validated['holidays'])],
                'Company holidays updated',
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 403);
        }
    }

    public function leaves(Request $request, int $employeeId): JsonResponse
    {
        try {
            $year = (int) $request->query('year', now()->year);

            return ApiResponse::success([
                'items' => $this->leaveService->listForEmployee($employeeId, $year),
                'can_manage' => $this->leaveService->canManage(),
            ], 'Employee leave records loaded');
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 403);
        }
    }

    public function storeLeave(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'employee_id' => ['required', 'integer'],
                'leave_date' => ['required', 'date'],
                'target_year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
                'reason' => ['nullable', 'string', 'max:500'],
            ]);

            return ApiResponse::success(
                $this->leaveService->requestLeave($validated),
                'Leave request submitted',
                201,
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    public function approveLeave(EmployeeLeave $employeeLeave): JsonResponse
    {
        try {
            return ApiResponse::success(
                $this->leaveService->approve($employeeLeave),
                'Leave approved',
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    public function rejectLeave(Request $request, EmployeeLeave $employeeLeave): JsonResponse
    {
        try {
            return ApiResponse::success(
                $this->leaveService->reject($employeeLeave, null, $request->input('reason')),
                'Leave rejected',
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    public function store(StoreYearlyEmployeeTargetRequest $request): JsonResponse
    {
        try {
            return ApiResponse::success(
                $this->targetService->store($request->validated()),
                'Yearly target assigned',
                201,
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 403);
        }
    }

    public function update(UpdateYearlyEmployeeTargetRequest $request, YearlyEmployeeTarget $yearlyEmployeeTarget): JsonResponse
    {
        try {
            return ApiResponse::success(
                $this->targetService->update($yearlyEmployeeTarget, $request->validated()),
                'Yearly target updated',
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 403);
        }
    }

    public function destroy(YearlyEmployeeTarget $yearlyEmployeeTarget): JsonResponse
    {
        try {
            $this->targetService->destroy($yearlyEmployeeTarget);

            return ApiResponse::success(null, 'Yearly target deleted');
        } catch (Throwable $e) {
            return ApiResponse::error($e->getMessage(), 403);
        }
    }
}
