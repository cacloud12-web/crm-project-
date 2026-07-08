<?php

namespace App\Services\Dashboard;

use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Models\AssignmentHistory;
use App\Models\BulkAction;
use App\Models\CaMaster;
use App\Models\ConsentTracking;
use App\Models\DndManagement;
use App\Models\EmailCampaign;
use App\Models\EmailLog;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\LeadAssignmentEngine;
use App\Models\SmsCampaign;
use App\Models\SmsLog;
use App\Models\SmsSetting;
use App\Models\WaMessageLog;
use App\Models\WhatsAppCampaign;
use App\Services\Cache\CrmCacheService;
use App\Services\DemoConfirmation\DemoConfirmationService;
use App\Services\FollowUp\ManagerFollowUpDashboardService;
use App\Services\Leads\DuplicateAttemptService;
use App\Services\Leads\EmployeeProductivityService;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Services\Reports\ReportsService;
use App\Support\Database\SqlAggregate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DashboardService
{
    public function __construct(
        private readonly ReportsService $reportsService,
        private readonly CrmCacheService $cacheService,
        private readonly EmployeeDataScopeService $employeeDataScope,
        private readonly DemoConfirmationService $demoConfirmationService,
        private readonly EmployeeProductivityService $employeeProductivity,
        private readonly ManagerFollowUpDashboardService $managerFollowUpDashboard,
        private readonly DuplicateAttemptService $duplicateAttemptService,
        private readonly ManagerEmployeeProductivityService $managerEmployeeProductivity,
    ) {}

    private const PIPELINE_STATUSES = [
        'Pipeline',
        'Warm',
        'Demo Scheduled',
        'Demo Completed',
        'Negotiation',
        'Details Shared',
    ];

    private const PIPELINE_STAGE_MAP = [
        'New' => 'New Lead',
        'Hot' => 'Negotiation',
        'Demo Scheduled' => 'Demo Scheduled',
        'Demo Completed' => 'Demo Completed',
        'Pipeline' => 'Details Shared',
        'Warm' => 'Details Shared',
        'Details Shared' => 'Details Shared',
        'Negotiation' => 'Negotiation',
        'Lost' => 'Lost',
        'Inactive' => 'Lost',
        'Active' => 'Won',
    ];

    private const PIPELINE_STAGES = [
        'New Lead',
        'Details Shared',
        'Demo Scheduled',
        'Demo Completed',
        'Negotiation',
        'Won',
        'Lost',
    ];

    private const OPEN_FOLLOWUP_STATUSES = [
        'Pending',
        'Scheduled',
        'Open',
        'Overdue',
    ];

    /**
     * @param  array{preset?: string, from?: string, to?: string}|null  $dateInput
     */
    public function metrics(?int $filterEmployeeId = null, ?array $dateInput = null): array
    {
        $viewerScope = $this->employeeDataScope->scopedEmployeeId(auth()->user());
        $range = DashboardDateRange::resolve(
            $dateInput['preset'] ?? 'today',
            $dateInput['from'] ?? null,
            $dateInput['to'] ?? null,
        );

        // Drop stale/invalid employee filters instead of failing the whole dashboard.
        if ($filterEmployeeId && $viewerScope === null && ! $this->managerEmployeeProductivity->canViewEmployee($filterEmployeeId)) {
            $filterEmployeeId = null;
        }

        $employeeId = $viewerScope ?? $filterEmployeeId;
        $scopeKey = $this->employeeDataScope->cacheScopeKey();
        if ($employeeId && $viewerScope === null) {
            $scopeKey = 'filter:'.$employeeId;
        }

        $filterKey = [
            'employee_id' => $employeeId,
            'preset' => $range['preset'],
            'from' => $range['from']->toDateString(),
            'to' => $range['to']->toDateString(),
        ];

        return $this->cacheService->rememberDashboardMetrics($scopeKey, $filterKey, function () use ($employeeId, $range) {
            return $this->buildMetrics($employeeId, $range);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function productivityEmployees(): array
    {
        return $this->managerEmployeeProductivity->listEmployees();
    }

    /**
     * @param  array{preset: string, from: Carbon, to: Carbon, label: string}  $range
     */
    private function buildMetrics(?int $employeeId, array $range): array
    {
        $from = $range['from'];
        $to = $range['to'];
        $leadCounts = $this->leadStatusCounts($employeeId, $from, $to);
        $totalLeads = (int) ($leadCounts['total'] ?? 0);
        $assignedLeads = $this->assignedLeadCount($employeeId, $from, $to);
        $followUpCounts = $this->followUpCounts($employeeId, $from, $to);
        $whatsappMetrics = $this->whatsappMetrics($employeeId, $from, $to);
        $emailMetrics = $this->emailMetrics($employeeId, $from, $to);
        $smsMetrics = $this->smsMetrics($employeeId, $from, $to);
        $safetyMetrics = $this->communicationSafetyMetrics();

        $reportParams = array_filter([
            'employee_id' => $employeeId,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ], fn ($v) => $v !== null);
        $employeeProductivity = null;
        try {
            $employeeProductivity = $this->managerEmployeeProductivity->productivity($employeeId, null, $range);
        } catch (InvalidArgumentException) {
            $employeeProductivity = null;
        }

        return [
            'filter_employee_id' => $employeeId,
            'date_range' => [
                'preset' => $range['preset'],
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'label' => $range['label'],
            ],
            'employee_productivity' => $employeeProductivity,
            'total_leads' => $totalLeads,
            'new_leads' => (int) ($leadCounts['new_established'] ?? 0),
            'hot_leads' => (int) ($leadCounts['hot'] ?? 0),
            'warm_leads' => (int) ($leadCounts['warm'] ?? 0),
            'cold_leads' => (int) ($leadCounts['cold'] ?? 0),
            'pipeline_leads' => (int) ($leadCounts['pipeline'] ?? 0),
            'lost_leads' => (int) ($leadCounts['lost'] ?? 0),
            'calls_total' => $this->callsTotal($employeeId, $from, $to),
            'meetings_today' => $this->meetingsTodayCount($employeeId, $from, $to),
            'active_employees' => $employeeId
                ? 1
                : Employee::query()->where('status', 'Active')->count(),
            'assigned_leads' => $assignedLeads,
            'unassigned_leads' => $employeeId ? 0 : max(0, $totalLeads - $assignedLeads),
            'followups_due_today' => $followUpCounts['due_today'],
            'overdue_followups' => $followUpCounts['overdue'],
            'bulk_import_total' => $employeeId ? 0 : BulkAction::query()
                ->where('action_type', 'ca_master_import')
                ->count(),
            'bulk_assignment_total' => $employeeId
                ? AssignmentHistory::query()->where('new_employee_id', $employeeId)->count()
                : AssignmentHistory::query()->count(),
            'whatsapp_campaigns_total' => $whatsappMetrics['campaigns_total'],
            'whatsapp_messages_total' => $whatsappMetrics['messages_total'],
            'whatsapp_delivered' => $whatsappMetrics['delivered'],
            'whatsapp_failed' => $whatsappMetrics['failed'],
            'whatsapp_queued' => $whatsappMetrics['queued'],
            'email_campaigns_total' => $emailMetrics['campaigns_total'],
            'email_messages_total' => $emailMetrics['messages_total'],
            'email_delivered' => $emailMetrics['delivered'],
            'email_failed' => $emailMetrics['failed'],
            'email_queued' => $emailMetrics['queued'],
            'sms_campaigns_total' => $smsMetrics['campaigns_total'],
            'sms_messages_total' => $smsMetrics['messages_total'],
            'sms_delivered' => $smsMetrics['delivered'],
            'sms_failed' => $smsMetrics['failed'],
            'sms_queued' => $smsMetrics['queued'],
            'sms_sent' => $smsMetrics['sent'],
            'sms_pending' => $smsMetrics['pending'],
            'sms_api_error' => $smsMetrics['api_error'],
            'sms_mapped' => $smsMetrics['mapped'],
            'sms_pending_campaigns' => $smsMetrics['pending_campaigns'],
            'sms_mode' => $smsMetrics['mode'],
            'sms_mode_simulation' => $smsMetrics['mode'] === SmsSetting::MODE_SIMULATION,
            'sms_mode_live' => $smsMetrics['mode'] === SmsSetting::MODE_LIVE,
            'dnd_contacts' => $safetyMetrics['dnd_contacts'],
            'consent_approved' => $safetyMetrics['consent_approved'],
            'consent_denied' => $safetyMetrics['consent_denied'],
            'skipped_due_to_dnd' => $safetyMetrics['skipped_due_to_dnd'],
            'skipped_due_to_no_consent' => $safetyMetrics['skipped_due_to_no_consent'],
            'demo_confirmations' => $this->demoConfirmationService->dashboardMetrics($employeeId),
            'reports' => $this->reportsService->dashboardInsights($reportParams),
            'productivity' => $this->employeeProductivity->managerDashboardWidgets(),
            'pipeline_breakdown' => $this->pipelineBreakdown($employeeId, $from, $to),
            'priority_leads' => $this->priorityLeads($employeeId, $from, $to),
            'recent_leads' => $this->recentLeads($employeeId, $from, $to),
            'team_summary' => $employeeId ? [] : $this->teamSummary(),
            'followup_manager' => $employeeId ? null : $this->managerFollowUpDashboard->metrics(),
            'duplicate_monitoring' => $employeeId ? null : $this->duplicateAttemptService->dashboardMetrics(),
            'activity_preview' => $this->activityPreview($employeeId, $from, $to),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function applyDateRange(Builder $query, string $column, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween($column, [$from, $to]);
    }

    private function leadStatusCounts(?int $employeeId, Carbon $from, Carbon $to): array
    {
        $query = CaMaster::query()->countableInStatistics();
        $this->employeeDataScope->scopeCaMasterQuery($query, $employeeId);
        $this->applyDateRange($query, 'ca_masters.created_at', $from, $to);

        $row = $query
            ->selectRaw('COUNT(*) as total')
            ->selectRaw(SqlAggregate::countFilter('*', "status = 'Hot'").' as hot')
            ->selectRaw(SqlAggregate::countFilter('*', "status = 'Warm'").' as warm')
            ->selectRaw(SqlAggregate::countFilter('*', "status = 'Cold'").' as cold')
            ->selectRaw(SqlAggregate::countFilter('*', 'status IN ('.$this->quotedList(self::PIPELINE_STATUSES).')').' as pipeline')
            ->selectRaw(SqlAggregate::countFilter('*', "status IN ('Lost', 'Inactive')").' as lost')
            ->selectRaw(SqlAggregate::countFilter('*', 'is_newly_established = true').' as new_established')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'hot' => (int) ($row->hot ?? 0),
            'warm' => (int) ($row->warm ?? 0),
            'cold' => (int) ($row->cold ?? 0),
            'pipeline' => (int) ($row->pipeline ?? 0),
            'lost' => (int) ($row->lost ?? 0),
            'new_established' => (int) ($row->new_established ?? 0),
        ];
    }

    private function assignedLeadCount(?int $employeeId, Carbon $from, Carbon $to): int
    {
        $query = LeadAssignmentEngine::query()
            ->where('status', 'Active')
            ->whereHas('caMaster', fn ($q) => $q->countableInStatistics());
        $this->employeeDataScope->scopeLeadAssignmentQuery($query, $employeeId);
        $this->applyDateRange($query, 'assigned_date', $from, $to);

        return (int) $query->distinct()->count('ca_id');
    }

    private function followUpCounts(?int $employeeId, Carbon $from, Carbon $to): array
    {
        $query = FollowUp::query()->whereIn('status', self::OPEN_FOLLOWUP_STATUSES);
        $this->employeeDataScope->scopeFollowUpQuery($query, $employeeId);
        $this->applyDateRange($query, 'scheduled_date', $from, $to);

        $row = $query
            ->selectRaw('COUNT(*) as due_today')
            ->first();

        $overdueQuery = FollowUp::query()
            ->whereIn('status', self::OPEN_FOLLOWUP_STATUSES)
            ->where('scheduled_date', '<', $from);
        $this->employeeDataScope->scopeFollowUpQuery($overdueQuery, $employeeId);

        return [
            'due_today' => (int) ($row->due_today ?? 0),
            'overdue' => (int) $overdueQuery->count(),
        ];
    }

    private function whatsappMetrics(?int $employeeId, Carbon $from, Carbon $to): array
    {
        $query = WaMessageLog::query();
        if ($employeeId) {
            $query->where('employee_id', $employeeId);
        }
        $this->applyDateRange($query, 'created_at', $from, $to);

        $statusCounts = $query
            ->selectRaw(SqlAggregate::countFilter('*', "message_status = 'Delivered'").' as delivered')
            ->selectRaw(SqlAggregate::countFilter('*', "message_status = 'Failed'").' as failed')
            ->selectRaw(SqlAggregate::countFilter('*', "message_status = 'Queued'").' as queued')
            ->selectRaw('COUNT(*) as total')
            ->first();

        return [
            'campaigns_total' => $employeeId ? 0 : WhatsAppCampaign::query()->count(),
            'messages_total' => (int) ($statusCounts->total ?? 0),
            'delivered' => (int) ($statusCounts->delivered ?? 0),
            'failed' => (int) ($statusCounts->failed ?? 0),
            'queued' => (int) ($statusCounts->queued ?? 0),
        ];
    }

    private function emailMetrics(?int $employeeId, Carbon $from, Carbon $to): array
    {
        $query = EmailLog::query();
        if ($employeeId) {
            $query->where('employee_id', $employeeId);
        }
        $this->applyDateRange($query, 'created_at', $from, $to);

        $statusCounts = $query
            ->selectRaw(SqlAggregate::countFilter('*', "email_status IN ('Delivered', 'Sent')").' as delivered')
            ->selectRaw(SqlAggregate::countFilter('*', "email_status = 'Failed'").' as failed')
            ->selectRaw(SqlAggregate::countFilter('*', "email_status = 'Queued'").' as queued')
            ->selectRaw('COUNT(*) as total')
            ->first();

        return [
            'campaigns_total' => $employeeId ? 0 : EmailCampaign::query()->count(),
            'messages_total' => (int) ($statusCounts->total ?? 0),
            'delivered' => (int) ($statusCounts->delivered ?? 0),
            'failed' => (int) ($statusCounts->failed ?? 0),
            'queued' => (int) ($statusCounts->queued ?? 0),
        ];
    }

    private function smsMetrics(?int $employeeId, Carbon $from, Carbon $to): array
    {
        $query = SmsLog::query();
        if ($employeeId) {
            $query->where('employee_id', $employeeId);
        }
        $this->applyDateRange($query, 'created_at', $from, $to);

        $statusCounts = $query
            ->selectRaw(SqlAggregate::countFilter('*', "sms_status = 'Delivered'").' as delivered')
            ->selectRaw(SqlAggregate::countFilter('*', "sms_status = 'Failed'").' as failed')
            ->selectRaw(SqlAggregate::countFilter('*', "sms_status = 'Queued'").' as queued')
            ->selectRaw(SqlAggregate::countFilter('*', "sms_status = 'Mapped'").' as mapped')
            ->selectRaw(SqlAggregate::countFilter('*', "sms_status = 'Sent'").' as sent')
            ->selectRaw(SqlAggregate::countFilter('*', "sms_status = 'Pending'").' as pending')
            ->selectRaw(SqlAggregate::countFilter('*', "sms_status = 'API Error'").' as api_error')
            ->selectRaw('COUNT(*) as total')
            ->first();

        $settings = SmsSetting::query()->first();

        return [
            'campaigns_total' => SmsCampaign::query()->count(),
            'messages_total' => (int) ($statusCounts->total ?? 0),
            'delivered' => (int) ($statusCounts->delivered ?? 0) + (int) ($statusCounts->sent ?? 0),
            'failed' => (int) ($statusCounts->failed ?? 0) + (int) ($statusCounts->api_error ?? 0),
            'queued' => (int) ($statusCounts->queued ?? 0) + (int) ($statusCounts->pending ?? 0),
            'mapped' => (int) ($statusCounts->mapped ?? 0),
            'sent' => (int) ($statusCounts->sent ?? 0),
            'pending' => (int) ($statusCounts->pending ?? 0),
            'api_error' => (int) ($statusCounts->api_error ?? 0),
            'pending_campaigns' => SmsCampaign::query()->whereIn('status', ['Draft', 'Scheduled'])->count(),
            'mode' => $settings?->mode ?? SmsSetting::MODE_SIMULATION,
        ];
    }

    private function communicationSafetyMetrics(): array
    {
        $skipped = $this->skippedLogCountsByReason();
        $consent = ConsentTracking::query()
            ->selectRaw(SqlAggregate::countFilter('*', "consent_status = 'Yes'").' as approved')
            ->selectRaw(SqlAggregate::countFilter('*', "consent_status = 'No'").' as denied')
            ->first();

        return [
            'dnd_contacts' => DndManagement::query()->distinct('ca_id')->count('ca_id'),
            'consent_approved' => (int) ($consent->approved ?? 0),
            'consent_denied' => (int) ($consent->denied ?? 0),
            'skipped_due_to_dnd' => $skipped['dnd_optout'],
            'skipped_due_to_no_consent' => $skipped['no_consent'],
        ];
    }

    private function callsTotal(?int $employeeId, Carbon $from, Carbon $to): int
    {
        $query = FollowUp::query()->where('followup_type', 'Call');
        $this->employeeDataScope->scopeFollowUpQuery($query, $employeeId);
        $this->applyDateRange($query, 'scheduled_date', $from, $to);

        return (int) $query->count();
    }

    private function meetingsTodayCount(?int $employeeId, Carbon $from, Carbon $to): int
    {
        $query = FollowUp::query()
            ->whereIn('followup_type', ['Demo Scheduled', 'Demo Completed', 'Meeting']);
        $this->employeeDataScope->scopeFollowUpQuery($query, $employeeId);
        $this->applyDateRange($query, 'scheduled_date', $from, $to);

        return (int) $query->count();
    }

    /**
     * @return array{dnd_optout: int, no_consent: int}
     */
    private function skippedLogCountsByReason(): array
    {
        $wa = WaMessageLog::query()
            ->where('message_status', 'Skipped')
            ->whereIn('failed_reason', ['dnd_optout', 'no_consent'])
            ->selectRaw('failed_reason')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('failed_reason')
            ->pluck('total', 'failed_reason');
        $email = EmailLog::query()
            ->where('email_status', 'Skipped')
            ->whereIn('failed_reason', ['dnd_optout', 'no_consent'])
            ->selectRaw('failed_reason')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('failed_reason')
            ->pluck('total', 'failed_reason');
        $sms = SmsLog::query()
            ->where('sms_status', 'Skipped')
            ->whereIn('failed_reason', ['dnd_optout', 'no_consent'])
            ->selectRaw('failed_reason')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('failed_reason')
            ->pluck('total', 'failed_reason');

        return [
            'dnd_optout' => (int) ($wa['dnd_optout'] ?? 0) + (int) ($email['dnd_optout'] ?? 0) + (int) ($sms['dnd_optout'] ?? 0),
            'no_consent' => (int) ($wa['no_consent'] ?? 0) + (int) ($email['no_consent'] ?? 0) + (int) ($sms['no_consent'] ?? 0),
        ];
    }

    private function quotedList(array $values): string
    {
        return collect($values)
            ->map(fn (string $value) => DB::getPdo()->quote($value))
            ->implode(', ');
    }

    /**
     * @return list<array{stage: string, count: int}>
     */
    private function pipelineBreakdown(?int $employeeId, Carbon $from, Carbon $to): array
    {
        $query = CaMaster::query()->countableInStatistics();
        $this->employeeDataScope->scopeCaMasterQuery($query, $employeeId);
        $this->applyDateRange($query, 'ca_masters.created_at', $from, $to);

        $rows = $query
            ->selectRaw('status')
            ->selectRaw('COUNT(*) as lead_count')
            ->groupBy('status')
            ->get();

        $counts = array_fill_keys(self::PIPELINE_STAGES, 0);
        foreach ($rows as $row) {
            $stage = self::PIPELINE_STAGE_MAP[$row->status] ?? 'New Lead';
            $counts[$stage] = ($counts[$stage] ?? 0) + (int) $row->lead_count;
        }

        return collect($counts)
            ->map(fn (int $count, string $stage) => ['stage' => $stage, 'count' => $count])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function priorityLeads(?int $employeeId, Carbon $from, Carbon $to): array
    {
        $query = CaMaster::query()->countableInStatistics()->with(['city:city_id,city_name']);
        $this->employeeDataScope->scopeCaMasterQuery($query, $employeeId);
        $this->applyDateRange($query, 'ca_masters.updated_at', $from, $to);

        $leads = $query
            ->where(function ($builder) {
                $builder->where('status', 'Hot')
                    ->orWhere('status', 'Demo Scheduled')
                    ->orWhere('status', 'Negotiation');
            })
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get(['ca_id', 'firm_name', 'ca_name', 'city_id', 'status', 'updated_at']);

        return $this->mapLeadSummaries($leads);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentLeads(?int $employeeId, Carbon $from, Carbon $to): array
    {
        $query = CaMaster::query()->countableInStatistics()->with(['city:city_id,city_name']);
        $this->employeeDataScope->scopeCaMasterQuery($query, $employeeId);
        $this->applyDateRange($query, 'ca_masters.updated_at', $from, $to);

        $leads = $query
            ->orderByDesc('updated_at')
            ->limit(6)
            ->get(['ca_id', 'firm_name', 'ca_name', 'city_id', 'status', 'updated_at']);

        return $this->mapLeadSummaries($leads);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function teamSummary(): array
    {
        $assignmentCounts = LeadAssignmentEngine::query()
            ->join('ca_masters', 'ca_masters.ca_id', '=', 'lead_assignment_engines.ca_id')
            ->where('lead_assignment_engines.status', 'Active')
            ->where(function ($q) {
                $q->whereNull('ca_masters.mobile_no_type')
                    ->orWhere('ca_masters.mobile_no_type', 'mobile');
            })
            ->selectRaw('lead_assignment_engines.employee_id')
            ->selectRaw('COUNT(DISTINCT lead_assignment_engines.ca_id) as assigned_count')
            ->groupBy('lead_assignment_engines.employee_id')
            ->pluck('assigned_count', 'employee_id');

        return Employee::query()
            ->with('city:city_id,city_name')
            ->where('status', 'Active')
            ->orderBy('name')
            ->get(['employee_id', 'name', 'city_id'])
            ->map(function (Employee $employee) use ($assignmentCounts) {
                $achieved = (int) ($assignmentCounts->get($employee->employee_id) ?? 0);
                $target = max(20, $achieved ?: 20);

                return [
                    'employee_id' => $employee->employee_id,
                    'name' => $employee->name,
                    'city' => $employee->city?->city_name,
                    'achieved_leads' => $achieved,
                    'target_leads' => $target,
                    'daily_calls' => 0,
                    'demos' => 0,
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activityPreview(?int $employeeId, Carbon $from, Carbon $to): array
    {
        $query = ActivityLog::query();
        $this->applyDateRange($query, 'created_at', $from, $to);

        if ($employeeId) {
            $employee = Employee::query()->find($employeeId);
            if ($employee?->name) {
                $query->where('performed_by', $employee->name);
            }
        }

        return $query
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(fn (ActivityLog $log) => (new ActivityLogResource($log))->resolve())
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CaMaster>  $leads
     * @return list<array<string, mixed>>
     */
    private function mapLeadSummaries($leads): array
    {
        if ($leads->isEmpty()) {
            return [];
        }

        $executives = LeadAssignmentEngine::query()
            ->with('employee:employee_id,name')
            ->whereIn('ca_id', $leads->pluck('ca_id'))
            ->where('status', 'Active')
            ->get()
            ->keyBy('ca_id');

        return $leads->map(function (CaMaster $lead) use ($executives) {
            $assignment = $executives->get($lead->ca_id);
            $stage = self::PIPELINE_STAGE_MAP[$lead->status] ?? 'New Lead';

            return [
                'ca_id' => $lead->ca_id,
                'firm_name' => $lead->firm_name ?: $lead->ca_name,
                'ca_name' => $lead->ca_name,
                'city' => $lead->city?->city_name,
                'status' => $lead->status,
                'stage' => $stage,
                'executive' => $assignment?->employee?->name,
                'executive_id' => $assignment?->employee_id,
                'updated_at' => $lead->updated_at,
            ];
        })->values()->all();
    }
}
