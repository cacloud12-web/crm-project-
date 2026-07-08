<?php

namespace App\Http\Controllers\Leads;

use App\Http\Controllers\Controller;
use App\Services\Email\LeadEmailCommunicationService;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class LeadEmailCommunicationController extends Controller
{
    public function __construct(
        private readonly LeadEmailCommunicationService $communicationService,
        private readonly EmployeeDataScopeService $employeeDataScope,
    ) {}

    public function index(int $caId): JsonResponse
    {
        $this->employeeDataScope->ensureCanAccessCaMaster($caId);
        $items = $this->communicationService->timelineForLead($caId);

        return ApiResponse::success(
            [
                'items' => $items['timeline'],
                'threads' => $items['threads'],
            ],
            'Email communication history loaded',
        );
    }
}
