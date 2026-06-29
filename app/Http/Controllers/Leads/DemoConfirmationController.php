<?php

namespace App\Http\Controllers\Leads;

use App\Http\Controllers\Controller;
use App\Http\Resources\DemoConfirmationResource;
use App\Models\DemoConfirmation;
use App\Services\DemoConfirmation\DemoConfirmationService;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DemoConfirmationController extends Controller
{
    public function __construct(
        private readonly DemoConfirmationService $demoConfirmationService,
    ) {}

    public function metrics(): JsonResponse
    {
        $employeeId = app(EmployeeDataScopeService::class)
            ->scopedEmployeeId(auth()->user());

        return ApiResponse::success(
            $this->demoConfirmationService->dashboardMetrics($employeeId ?: null),
            'Demo confirmation metrics loaded',
        );
    }

    public function showForLead(string $leadId): JsonResponse
    {
        try {
            $this->demoConfirmationService->ensureCanAccessLead((int) $leadId);
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }

        $summary = $this->demoConfirmationService->summaryForLead((int) $leadId);
        $history = $this->demoConfirmationService->historyForLead((int) $leadId);

        return ApiResponse::success([
            'summary' => $summary,
            'history' => DemoConfirmationResource::collection($history),
            'timeline' => $this->demoConfirmationService->timelineForLead((int) $leadId),
        ]);
    }

    /**
     * Stub for future SMS Alert inbound webhook. Admin-only simulation during integration phase.
     */
    public function inboundReply(Request $request): JsonResponse
    {
        try {
            $this->demoConfirmationService->ensureCanSimulateReply();
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }

        $data = $request->validate([
            'demo_confirmation_id' => 'required_without:mobile_no|integer|exists:demo_confirmations,id',
            'mobile_no' => 'required_without:demo_confirmation_id|nullable|string|max:20',
            'reply' => 'required|string|max:50',
        ]);

        $confirmation = isset($data['demo_confirmation_id'])
            ? DemoConfirmation::query()->findOrFail($data['demo_confirmation_id'])
            : $this->resolveConfirmationByMobile((string) $data['mobile_no']);

        if (! $confirmation) {
            return ApiResponse::error('No pending demo confirmation found for this mobile number.', 404);
        }

        try {
            $updated = $this->demoConfirmationService->processInboundReply($confirmation, $data['reply']);
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(
            new DemoConfirmationResource($updated),
            'Customer reply processed',
        );
    }

    private function resolveConfirmationByMobile(string $mobileNo): ?DemoConfirmation
    {
        $digits = preg_replace('/\D/', '', $mobileNo) ?? '';
        if (strlen($digits) > 10 && str_starts_with($digits, '91')) {
            $digits = substr($digits, -10);
        }

        if ($digits === '') {
            return null;
        }

        return DemoConfirmation::query()
            ->where('confirmation_status', DemoConfirmation::STATUS_PENDING)
            ->whereHas('lead', fn ($q) => $q->where('mobile_no', $digits))
            ->orderByDesc('id')
            ->first();
    }
}
