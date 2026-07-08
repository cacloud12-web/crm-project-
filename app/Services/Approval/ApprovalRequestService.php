<?php

namespace App\Services\Approval;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use App\Services\FollowUp\FollowUpService;
use App\Services\Leads\CaMasterService;
use App\Services\Rbac\EmployeeDataScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalRequestService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly CaMasterService $caMasterService,
        private readonly FollowUpService $followUpService,
        private readonly EmployeeDataScopeService $employeeDataScope,
    ) {}

    public function search(array $params = []): array
    {
        $user = auth()->user();
        $query = ApprovalRequest::query()
            ->with(['caMaster', 'requestedBy', 'reviewedBy'])
            ->orderByDesc('created_at');

        if ($user && $user->crm_role === 'employee') {
            $query->where('requested_by_user_id', $user->id);
        }

        if (! empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        $perPage = min(max((int) ($params['per_page'] ?? 25), 1), 100);

        $paginator = $query->paginate($perPage);

        return [
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function submit(string $requestType, int $caId, array $payload, ?int $followupId = null): ApprovalRequest
    {
        $user = auth()->user();
        if (! $user) {
            abort(401);
        }

        $this->employeeDataScope->ensureCanAccessCaMaster($caId);

        $request = ApprovalRequest::query()->create([
            'request_type' => $requestType,
            'ca_id' => $caId,
            'followup_id' => $followupId,
            'requested_by_user_id' => $user->id,
            'payload' => $payload,
            'status' => 'pending',
        ]);

        $this->activityLogService->log(
            'SECURITY',
            'Approval Requested',
            (string) $caId,
            $requestType.' pending manager review',
            $user->name,
            afterValue: $payload,
        );

        return $request->load(['caMaster', 'requestedBy']);
    }

    public function approve(int $requestId, ?string $remarks = null): ApprovalRequest
    {
        $this->assertCanReview();

        return DB::transaction(function () use ($requestId, $remarks) {
            $request = ApprovalRequest::query()->lockForUpdate()->findOrFail($requestId);

            if ($request->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => ['This approval request has already been processed.'],
                ]);
            }

            $this->applyApprovedRequest($request);

            $request->update([
                'status' => 'approved',
                'reviewed_by_user_id' => auth()->id(),
                'review_remarks' => $remarks,
                'reviewed_at' => now(),
            ]);

            $this->activityLogService->log(
                'SECURITY',
                'Approval Granted',
                (string) $request->ca_id,
                $request->request_type.' approved',
                auth()->user()?->name ?? 'System',
            );

            return $request->fresh(['caMaster', 'requestedBy', 'reviewedBy']);
        });
    }

    public function reject(int $requestId, ?string $remarks = null): ApprovalRequest
    {
        $this->assertCanReview();

        $request = ApprovalRequest::query()->findOrFail($requestId);

        if ($request->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ['This approval request has already been processed.'],
            ]);
        }

        $request->update([
            'status' => 'rejected',
            'reviewed_by_user_id' => auth()->id(),
            'review_remarks' => $remarks,
            'reviewed_at' => now(),
        ]);

        $this->activityLogService->log(
            'SECURITY',
            'Approval Rejected',
            (string) $request->ca_id,
            $request->request_type.' rejected',
            auth()->user()?->name ?? 'System',
        );

        return $request->fresh(['caMaster', 'requestedBy', 'reviewedBy']);
    }

    private function applyApprovedRequest(ApprovalRequest $request): void
    {
        $payload = $request->payload ?? [];

        match ($request->request_type) {
            'lead_status_change' => $this->caMasterService->updateStatus(
                $this->caMasterService->find($request->ca_id),
                (string) ($payload['status'] ?? ''),
            ),
            'lead_action' => app(\App\Services\LeadAction\LeadActionService::class)->apply(
                $request->ca_id,
                (string) ($payload['action_type'] ?? ''),
                $payload['remarks'] ?? null,
            ),
            'demo_reschedule' => $this->followUpService->update(
                $this->followUpService->find((int) $request->followup_id),
                [
                    'scheduled_date' => $payload['scheduled_date'] ?? null,
                    'next_followup_date' => $payload['next_followup_date'] ?? null,
                    'reschedule_reason' => $payload['reschedule_reason'] ?? null,
                ],
            ),
            default => throw ValidationException::withMessages([
                'request_type' => ['Unknown approval request type.'],
            ]),
        };
    }

    private function assertCanReview(): void
    {
        $role = auth()->user()?->crm_role;

        if (! in_array($role, ['super_admin', 'admin', 'manager'], true)) {
            abort(403, 'Only managers and administrators can review approval requests.');
        }
    }
}
