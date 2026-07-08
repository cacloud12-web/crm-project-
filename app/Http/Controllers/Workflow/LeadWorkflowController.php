<?php

namespace App\Http\Controllers\Workflow;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\RecordCallRequest;
use App\Http\Requests\Workflow\RecordDemoResultRequest;
use App\Http\Requests\Workflow\ScheduleDemoRequest;
use App\Http\Resources\FollowUpResource;
use App\Services\Workflow\LeadWorkflowService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class LeadWorkflowController extends Controller
{
    public function __construct(
        private readonly LeadWorkflowService $workflowService,
    ) {}

    public function recordCall(RecordCallRequest $request): JsonResponse
    {
        try {
            $result = $this->workflowService->recordCall($request->validated());

            return ApiResponse::success([
                'call_log' => $result['call_log'],
                'next_follow_up' => $result['next_follow_up']
                    ? new FollowUpResource($result['next_follow_up'])
                    : null,
                'outcome' => $result['outcome'],
            ], 'Call logged');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    public function scheduleDemo(ScheduleDemoRequest $request): JsonResponse
    {
        try {
            $result = $this->workflowService->scheduleDemo($request->validated());

            return ApiResponse::success([
                'demo_schedule' => $result['demo_schedule'],
                'follow_up' => $result['follow_up']
                    ? new FollowUpResource($result['follow_up'])
                    : null,
            ], 'Demo scheduled');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    public function resolveDemo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'followup_id' => 'nullable|integer|exists:follow_ups,followup_id',
            'ca_id' => 'required_without:followup_id|integer|exists:ca_masters,ca_id',
        ]);

        try {
            $schedule = $this->workflowService->resolveDemoSchedule($validated);

            return ApiResponse::success([
                'demo_schedule' => $schedule,
                'demo_results' => config('lead_workflow.demo_results', []),
            ], 'Demo schedule ready');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    public function recordDemoResult(int $demoSchedule, RecordDemoResultRequest $request): JsonResponse
    {
        try {
            $result = $this->workflowService->recordDemoResult($demoSchedule, $request->validated());

            return ApiResponse::success([
                'demo_result' => $result['demo_result'],
                'next_follow_up' => $result['next_follow_up']
                    ? new FollowUpResource($result['next_follow_up'])
                    : null,
                'purchase' => $result['purchase'],
            ], 'Demo result saved');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    public function lists(): JsonResponse
    {
        return ApiResponse::success(
            $this->workflowService->lists(),
            'Workflow lists loaded',
        );
    }

    public function demoHistory(): JsonResponse
    {
        // Repair older completed demos so they appear under Demo Completed filters.
        $this->workflowService->syncCompletedDemoFollowUpTypes();

        return ApiResponse::success(
            $this->workflowService->demoHistory(),
            'Demo history loaded',
        );
    }

    public function options(): JsonResponse
    {
        $plans = config('sales_plans.plans', []);

        return ApiResponse::success([
            'call_statuses' => config('lead_workflow.call_statuses', []),
            'demo_results' => config('lead_workflow.demo_results', []),
            'purchase_plans' => array_keys($plans),
            'plan_configs' => $plans,
        ], 'Workflow options loaded');
    }
}
