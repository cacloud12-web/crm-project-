<?php

namespace App\Http\Controllers\Approval;

use App\Http\Controllers\Controller;
use App\Services\Approval\ApprovalRequestService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalRequestController extends Controller
{
    public function __construct(
        private readonly ApprovalRequestService $approvalRequestService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->approvalRequestService->search($request->query());

        return ApiResponse::success($result, 'Approval requests loaded');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'request_type' => 'required|string|in:lead_status_change,lead_action,demo_reschedule',
            'ca_id' => 'required|integer|exists:ca_masters,ca_id',
            'followup_id' => 'nullable|integer|exists:follow_ups,followup_id',
            'payload' => 'required|array',
        ]);

        $record = $this->approvalRequestService->submit(
            $validated['request_type'],
            (int) $validated['ca_id'],
            $validated['payload'],
            isset($validated['followup_id']) ? (int) $validated['followup_id'] : null,
        );

        return ApiResponse::created($record, 'Approval request submitted');
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $remarks = $request->validate(['remarks' => 'nullable|string|max:2000'])['remarks'] ?? null;

        $record = $this->approvalRequestService->approve($id, $remarks);

        return ApiResponse::success($record, 'Approval request approved');
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $remarks = $request->validate(['remarks' => 'nullable|string|max:2000'])['remarks'] ?? null;

        $record = $this->approvalRequestService->reject($id, $remarks);

        return ApiResponse::success($record, 'Approval request rejected');
    }
}
