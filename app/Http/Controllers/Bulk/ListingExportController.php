<?php

namespace App\Http\Controllers\Bulk;

use App\Http\Controllers\Controller;
use App\Models\CaMaster;
use App\Models\ConsentTracking;
use App\Models\DndManagement;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\LeadAssignmentEngine;
use App\Services\Listing\ListingExportService;
use App\Support\Listing\ListingQueryApplier;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListingExportController extends Controller
{
    public function __construct(
        private readonly ListingExportService $listingExportService,
    ) {}

    public function export(Request $request, string $listingKey): StreamedResponse
    {
        [$query, $config, $columns] = $this->resolveListing($listingKey);

        return $this->listingExportService->exportCsv(
            $query,
            $request->query(),
            $config,
            $columns,
        );
    }

    private function resolveListing(string $listingKey): array
    {
        return match ($listingKey) {
            'ca_masters' => [
                CaMaster::query()->with(['city', 'state', 'sourceLead']),
                ListingQueryApplier::config('ca_masters'),
                [
                    'ca_id' => 'CA ID',
                    'firm_name' => 'Firm',
                    'ca_name' => 'CA Name',
                    'mobile_no' => 'Mobile',
                    'alternate_mobile_no' => 'Alternate Mobile',
                    'email_id' => 'Email',
                    'status' => 'Status',
                    'city.city_name' => 'City',
                    'state.state_name' => 'State',
                ],
            ],
            'employees' => [
                Employee::query()->with('city'),
                ListingQueryApplier::config('employees'),
                [
                    'employee_id' => 'Employee ID',
                    'name' => 'Name',
                    'email_id' => 'Email',
                    'role' => 'Role',
                    'status' => 'Status',
                    'city.city_name' => 'City',
                ],
            ],
            'follow_ups' => [
                FollowUp::query()->with(['caMaster', 'employee']),
                ListingQueryApplier::config('follow_ups'),
                [
                    'followup_id' => 'Follow-up ID',
                    'caMaster.firm_name' => 'Firm',
                    'followup_type' => 'Type',
                    'status' => 'Status',
                    'scheduled_date' => 'Scheduled',
                    'employee.name' => 'Executive',
                ],
            ],
            'lead_assignments' => [
                LeadAssignmentEngine::query()->with(['caMaster', 'employee']),
                ListingQueryApplier::config('lead_assignments'),
                [
                    'assignment_id' => 'Assignment ID',
                    'caMaster.firm_name' => 'Firm',
                    'employee.name' => 'Executive',
                    'assignment_type' => 'Type',
                    'status' => 'Status',
                ],
            ],
            'consent_trackings' => [
                ConsentTracking::query()->with('caMaster'),
                ListingQueryApplier::config('consent_trackings'),
                [
                    'id' => 'ID',
                    'caMaster.firm_name' => 'Firm',
                    'consent_type' => 'Type',
                    'consent_status' => 'Status',
                    'consent_date' => 'Date',
                ],
            ],
            'dnd_management' => [
                DndManagement::query()->with('caMaster'),
                ListingQueryApplier::config('dnd_management'),
                [
                    'id' => 'ID',
                    'caMaster.firm_name' => 'Firm',
                    'dnd_type' => 'Channel',
                    'dnd_status' => 'Status',
                ],
            ],
            default => abort(404, 'Unknown listing export key'),
        };
    }
}
