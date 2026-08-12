<?php

namespace App\Listeners;

use App\Events\LeadSaved;
use App\Services\Leads\EmployeeProductivityService;
use App\Services\Rbac\EmployeeDataScopeService;
use Illuminate\Support\Facades\Log;

/**
 * Recompute daily productivity after lead create/update.
 *
 * Runs after the HTTP response is sent so save/edit requests are not blocked by
 * the multi-COUNT snapshot refresh (ca_masters, follow_ups, logs, etc.).
 */
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

        if (! $employeeId) {
            return;
        }

        $employeeId = (int) $employeeId;

        // Prefer afterResponse so the client receives "saved" without waiting.
        // Fallback to sync if the dispatcher cannot defer (tests / CLI edge cases).
        try {
            dispatch(function () use ($employeeId): void {
                try {
                    app(EmployeeProductivityService::class)->refreshDailySnapshot($employeeId);
                } catch (\Throwable $e) {
                    Log::warning('Deferred productivity refresh failed', [
                        'employee_id' => $employeeId,
                        'message' => $e->getMessage(),
                    ]);
                }
            })->afterResponse();
        } catch (\Throwable) {
            $this->productivityService->refreshDailySnapshot($employeeId);
        }
    }
}
