<?php

namespace App\Services\Search;

use App\Models\CaMaster;
use App\Models\EmailCampaign;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\SmsCampaign;
use App\Models\WhatsAppCampaign;
use App\Services\Rbac\EmployeeDataScopeService;

class GlobalSearchService
{
    public function __construct(
        private readonly EmployeeDataScopeService $employeeDataScope,
    ) {}

    public function search(string $query, int $limit = 8): array
    {
        $term = trim($query);
        if ($term === '') {
            return [];
        }

        $employeeId = $this->employeeDataScope->scopedEmployeeId(auth()->user());
        $perGroup = max(2, (int) ceil($limit / 4));
        $results = [];

        $leadQuery = CaMaster::query()
            ->with(['city', 'state'])
            ->where(function ($q) use ($term) {
                $like = '%'.$term.'%';
                $q->where('firm_name', 'ilike', $like)
                    ->orWhere('ca_name', 'ilike', $like)
                    ->orWhere('mobile_no', 'ilike', $like)
                    ->orWhere('alternate_mobile_no', 'ilike', $like)
                    ->orWhere('email_id', 'ilike', $like);
            });
        $this->employeeDataScope->scopeCaMasterQuery($leadQuery, $employeeId);

        foreach ($leadQuery->limit($perGroup)->get() as $lead) {
            $results[] = [
                'type' => 'Lead',
                'title' => $lead->firm_name ?: $lead->ca_name,
                'meta' => trim(($lead->city?->city_name ?? '').' · '.($lead->status ?? '')),
                'page' => $employeeId === null ? 'ca-master' : 'leads',
                'icon' => 'building-2',
                'record_id' => (string) $lead->ca_id,
            ];
        }

        if ($employeeId === null) {
            Employee::query()
                ->where(function ($q) use ($term) {
                    $like = '%'.$term.'%';
                    $q->where('name', 'ilike', $like)
                        ->orWhere('email_id', 'ilike', $like)
                        ->orWhere('mobile_no', 'ilike', $like);
                })
                ->limit($perGroup)
                ->get()
                ->each(function (Employee $employee) use (&$results) {
                    $results[] = [
                        'type' => 'Employee',
                        'title' => $employee->name,
                        'meta' => ($employee->role ?? 'Employee').' · '.$employee->email_id,
                        'page' => 'assignment',
                        'icon' => 'user',
                        'record_id' => (string) $employee->employee_id,
                    ];
                });
        }

        $followUpQuery = FollowUp::query()
            ->with(['caMaster', 'employee'])
            ->where(function ($q) use ($term) {
                $like = '%'.$term.'%';
                $q->where('followup_type', 'ilike', $like)
                    ->orWhere('remarks', 'ilike', $like)
                    ->orWhereHas('caMaster', fn ($lead) => $lead->where('firm_name', 'ilike', $like));
            });
        $this->employeeDataScope->scopeFollowUpQuery($followUpQuery, $employeeId);

        foreach ($followUpQuery->limit($perGroup)->get() as $followUp) {
            $results[] = [
                'type' => 'Follow-up',
                'title' => $followUp->caMaster?->firm_name ?? 'Follow-up #'.$followUp->followup_id,
                'meta' => ($followUp->followup_type ?? 'Follow-up').' · '.($followUp->status ?? 'Pending'),
                'page' => 'followups',
                'icon' => 'calendar-clock',
                'record_id' => (string) $followUp->followup_id,
            ];
        }

        if ($employeeId === null) {
            $campaignModels = [
                [WhatsAppCampaign::class, 'WhatsApp', 'whatsapp', 'message-circle'],
                [EmailCampaign::class, 'Email', 'email', 'mail'],
                [SmsCampaign::class, 'SMS', 'sms', 'smartphone'],
            ];

            foreach ($campaignModels as [$model, $label, $page, $icon]) {
                $model::query()
                    ->where('campaign_name', 'ilike', '%'.$term.'%')
                    ->limit(2)
                    ->get()
                    ->each(function ($campaign) use (&$results, $label, $page, $icon) {
                        $results[] = [
                            'type' => $label.' Campaign',
                            'title' => $campaign->campaign_name,
                            'meta' => ($campaign->campaign_type ?? 'Campaign').' · '.($campaign->status ?? ''),
                            'page' => $page,
                            'icon' => $icon,
                            'record_id' => (string) $campaign->id,
                        ];
                    });
            }
        }

        return array_slice($results, 0, $limit);
    }
}
