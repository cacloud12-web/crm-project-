<?php

namespace App\Http\Controllers\FollowUp;

use App\Http\Controllers\Controller;
use App\Http\Requests\FollowUp\RecordCallOutcomeRequest;
use App\Http\Requests\FollowUp\UpdateFollowUpSequenceRequest;
use App\Http\Resources\FollowUpHistoryResource;
use App\Http\Resources\FollowUpResource;
use App\Http\Resources\FollowUpSequenceResource;
use App\Http\Resources\TaskResource;
use App\Services\FollowUp\FollowUpAutomationService;
use App\Services\FollowUp\FollowUpHistoryService;
use App\Services\FollowUp\FollowUpSequenceService;
use App\Services\FollowUp\FollowUpService;
use App\Services\FollowUp\ManagerFollowUpDashboardService;
use App\Services\FollowUp\TaskService;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class FollowUpAutomationController extends Controller
{
    public function __construct(
        private readonly FollowUpAutomationService $automationService,
        private readonly FollowUpService $followUpService,
        private readonly FollowUpHistoryService $historyService,
        private readonly FollowUpSequenceService $sequenceService,
        private readonly TaskService $taskService,
        private readonly ManagerFollowUpDashboardService $managerDashboardService,
    ) {}

    public function recordCallOutcome(RecordCallOutcomeRequest $request): JsonResponse
    {
        try {
            $result = $this->automationService->recordCallOutcome($request->validated());

            return ApiResponse::success([
                'completed_follow_up' => $result['completed_follow_up']
                    ? new FollowUpResource($result['completed_follow_up']->load(['caMaster', 'employee']))
                    : null,
                'next_follow_up' => $result['next_follow_up']
                    ? new FollowUpResource($result['next_follow_up']->load(['caMaster', 'employee']))
                    : null,
                'outcome' => $result['outcome'],
            ], 'Call outcome recorded');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    public function leadHistory(int $caId): JsonResponse
    {
        $items = $this->followUpService->historyForLead($caId);

        return ApiResponse::success(
            FollowUpHistoryResource::collection($items),
            'Follow-up history loaded',
        );
    }

    public function followUpHistory(int $followupId): JsonResponse
    {
        $items = $this->historyService->timelineForFollowUp($followupId);

        return ApiResponse::success(
            FollowUpHistoryResource::collection($items),
            'Follow-up history loaded',
        );
    }

    public function tasks(Request $request): JsonResponse
    {
        $employeeId = $request->integer('employee_id') ?: null;
        $user = auth()->user();
        if ($employeeId === null && $user) {
            $employeeId = app(EmployeeDataScopeService::class)->scopedEmployeeId($user);
        }

        $tasks = $this->taskService->listForEmployee($employeeId, $request->query());

        return ApiResponse::success(TaskResource::collection($tasks), 'Tasks loaded');
    }

    public function sequenceShow(): JsonResponse
    {
        return ApiResponse::success(
            new FollowUpSequenceResource($this->sequenceService->activeConfig()),
            'Follow-up sequence loaded',
        );
    }

    public function sequenceUpdate(UpdateFollowUpSequenceRequest $request): JsonResponse
    {
        $config = $this->sequenceService->updateConfig(
            $request->validated(),
            auth()->id(),
        );

        return ApiResponse::success(
            new FollowUpSequenceResource($config),
            'Follow-up sequence updated',
        );
    }

    public function managerMetrics(): JsonResponse
    {
        return ApiResponse::success(
            $this->managerDashboardService->metrics(),
            'Manager follow-up metrics loaded',
        );
    }
}
