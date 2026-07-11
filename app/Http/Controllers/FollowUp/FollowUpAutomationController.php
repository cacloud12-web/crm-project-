<?php

namespace App\Http\Controllers\FollowUp;

use App\Http\Controllers\Controller;
use App\Http\Requests\FollowUp\RecordCallOutcomeRequest;
use App\Http\Requests\FollowUp\UpdateFollowUpSequenceRequest;
use App\Http\Resources\FollowUpResource;
use App\Http\Resources\FollowUpSequenceResource;
use App\Http\Resources\LeadActivityTimelineResource;
use App\Http\Resources\TaskResource;
use App\Services\FollowUp\FollowUpAutomationService;
use App\Services\FollowUp\FollowUpHistoryService;
use App\Services\FollowUp\FollowUpSequenceService;
use App\Services\FollowUp\FollowUpService;
use App\Services\FollowUp\LeadActivityTimelineService;
use App\Services\FollowUp\ManagerFollowUpDashboardService;
use App\Services\FollowUp\TaskService;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Services\Workflow\LeadWorkflowService;
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
        private readonly EmployeeDataScopeService $employeeDataScope,
        private readonly LeadWorkflowService $workflowService,
        private readonly LeadActivityTimelineService $activityTimelineService,
    ) {}

    public function recordCallOutcome(RecordCallOutcomeRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $outcome = (string) ($data['outcome'] ?? '');

            if ($outcome === 'Demo Scheduled') {
                $this->workflowService->recordCall([
                    'ca_id' => $data['ca_id'] ?? null,
                    'followup_id' => $data['followup_id'] ?? null,
                    'employee_id' => $data['employee_id'] ?? null,
                    'call_status' => 'Demo Scheduled',
                    'call_note' => $data['remarks'],
                ]);

                $demo = $this->workflowService->scheduleDemo([
                    'ca_id' => $data['ca_id'] ?? null,
                    'followup_id' => $data['followup_id'] ?? null,
                    'employee_id' => $data['employee_id'] ?? null,
                    'demo_at' => $data['demo_at'] ?? null,
                    'demo_date' => $data['demo_date'] ?? null,
                    'demo_time' => $data['demo_time'] ?? null,
                    'meeting_link' => $data['meeting_link'] ?? '',
                    'notes' => $data['remarks'] ?? null,
                ]);

                return ApiResponse::success([
                    'completed_follow_up' => null,
                    'next_follow_up' => $demo['follow_up']
                        ? new FollowUpResource($demo['follow_up'])
                        : null,
                    'demo_schedule' => $demo['demo_schedule'],
                    'outcome' => 'Demo Scheduled',
                ], 'Demo scheduled');
            }

            $result = $this->workflowService->recordCall([
                'ca_id' => $data['ca_id'] ?? null,
                'followup_id' => $data['followup_id'] ?? null,
                'employee_id' => $data['employee_id'] ?? null,
                'call_status' => $outcome,
                'call_note' => $data['remarks'] ?? null,
                'next_followup_date' => $data['next_followup_date'] ?? null,
                'next_followup_time' => $data['next_followup_time'] ?? null,
            ]);

            return ApiResponse::success([
                'completed_follow_up' => null,
                'next_follow_up' => $result['next_follow_up']
                    ? new FollowUpResource($result['next_follow_up'])
                    : null,
                'call_log' => $result['call_log'],
                'outcome' => $result['outcome'],
            ], 'Call outcome recorded');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    public function leadHistory(int $caId, Request $request): JsonResponse
    {
        $this->employeeDataScope->ensureCanAccessCaMaster($caId);
        $sort = $request->query('sort', 'desc');
        $items = $this->activityTimelineService->forLead($caId, (string) $sort);

        return ApiResponse::success(
            LeadActivityTimelineResource::collection($items),
            'Follow-up history loaded',
        );
    }

    public function followUpHistory(int $followupId, Request $request): JsonResponse
    {
        $this->employeeDataScope->ensureCanAccessFollowUp($followupId);
        $sort = $request->query('sort', 'desc');
        $items = $this->activityTimelineService->forFollowUp($followupId, (string) $sort);

        return ApiResponse::success(
            LeadActivityTimelineResource::collection($items),
            'Follow-up history loaded',
        );
    }

    public function activityTimeline(Request $request): JsonResponse
    {
        $result = $this->activityTimelineService->feed(auth()->user(), $request->query());

        return ApiResponse::success([
            'items' => LeadActivityTimelineResource::collection($result['items']),
            'pagination' => $result['pagination'],
        ], 'Activity timeline loaded');
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
        $this->employeeDataScope->ensureCanViewManagerMetrics(auth()->user());

        return ApiResponse::success(
            $this->managerDashboardService->metrics(),
            'Manager follow-up metrics loaded',
        );
    }
}
