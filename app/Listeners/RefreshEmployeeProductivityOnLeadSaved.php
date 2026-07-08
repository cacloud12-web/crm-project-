<?php

namespace App\Listeners;

use App\Events\LeadSaved;
use App\Services\Leads\EmployeeProductivityService;
use App\Services\Rbac\EmployeeDataScopeService;

class RefreshEmployeeProductivityOnLeadSaved
{
    public function __construct(
        private readonly EmployeeProductivityService $productivityService,
        private readonly EmployeeDataScopeService $employeeDataScope,
    ) {}

    public function handle(LeadSaved $event): void
    {
        $employeeId = $event->lead->created_by_employee_id
            ?: $this->employeeDataScope->resolveEmployeeId($event->actor);

        if ($employeeId) {
            $this->productivityService->refreshDailySnapshot((int) $employeeId);
        }
    }
}
