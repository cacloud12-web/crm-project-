<?php

namespace App\Services\Leads;

use App\Models\CaMaster;
use App\Models\LeadView;
use App\Models\User;
use App\Services\Rbac\EmployeeDataScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;

class LeadViewService
{
    public function __construct(
        private readonly EmployeeDataScopeService $employeeDataScope,
    ) {}

    public function recordView(CaMaster $lead, ?User $user = null, ?Request $request = null): void
    {
        $user = $user ?? auth()->user();
        if (! $user) {
            return;
        }

        $request = $request ?? RequestFacade::getFacadeRoot();
        $employeeId = $this->employeeDataScope->scopedEmployeeId($user);

        LeadView::query()->create([
            'ca_id' => $lead->ca_id,
            'user_id' => $user->id,
            'employee_id' => $employeeId,
            'ip_address' => $request?->ip(),
            'user_agent' => (string) $request?->userAgent(),
            'viewed_at' => now(),
        ]);

        $lead->update([
            'view_count' => (int) $lead->view_count + 1,
            'last_viewed_at' => now(),
        ]);
    }
}
