<?php

namespace App\Services\LeadAction;

use App\Models\LeadAction;
use App\Services\Activity\ActivityLogService;
use App\Services\Leads\CaMasterService;
use App\Services\Rbac\EmployeeDataScopeService;

class LeadActionService
{
    private const ACTION_STATUS_MAP = [
        'Move to Demo Tab' => 'Demo Scheduled',
        'Details Shared' => 'Pipeline',
        'Not Interested' => 'Lost',
        'Negotiation' => 'Negotiation',
        'Pipeline' => 'Pipeline',
        'Mark Inactive' => 'Inactive',
    ];

    public function __construct(
        private readonly CaMasterService $caMasterService,
        private readonly ActivityLogService $activityLogService,
        private readonly EmployeeDataScopeService $employeeDataScope,
    ) {}

    public function apply(int $caId, string $actionType, ?string $remarks = null): LeadAction
    {
        $status = self::ACTION_STATUS_MAP[$actionType] ?? null;

        if (! $status) {
            throw new \InvalidArgumentException('Unknown lead action: '.$actionType);
        }

        $lead = $this->caMasterService->find($caId);
        $before = $lead->status;

        $this->caMasterService->updateStatus($lead, $status);

        $employeeId = $this->employeeDataScope->scopedEmployeeId(auth()->user());

        $record = LeadAction::create([
            'ca_id' => $caId,
            'employee_id' => $employeeId,
            'action_type' => $actionType,
            'action_at' => now(),
            'remarks' => $remarks,
        ]);

        $this->activityLogService->log(
            'LEAD_ACTION',
            'Lead Action',
            (string) $caId,
            $actionType.' — status '.$before.' → '.$status,
            auth()->user()?->name ?? 'System',
            beforeValue: $before,
            afterValue: $status,
        );

        return $record->load(['caMaster', 'employee']);
    }
}
