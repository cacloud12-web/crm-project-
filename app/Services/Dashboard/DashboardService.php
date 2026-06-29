<?php

namespace App\Services\Dashboard;

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
use App\Services\Rbac\EmployeeDataScopeService;
use App\Services\Reports\ReportsService;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function __construct(
        private readonly ReportsService $reportsService,
        private readonly CrmCacheService $cacheService,
        private readonly EmployeeDataScopeService $employeeDataScope,
        private readonly DemoConfirmationService $demoConfirmationService,
    ) {}

    private const PIPELINE_STATUSES = [
        'Pipeline',
        'Warm',
        'Demo Scheduled',
        'Demo Completed',
        'Negotiation',
        'Details Shared',
    ];

    private const OPEN_FOLLOWUP_STATUSES = [
        'Pending',
        'Scheduled',
        'Open',
        'Overdue',
    ];

    public function metrics(): array
    {
        $scopeKey = $this->employeeDataScope->cacheScopeKey();

        return $this->cacheService->rememberDashboardMetrics($scopeKey, function () {
            return $this->buildMetrics();
        });
    }

    private function buildMetrics(): array
    {
        $employeeId = $this->employeeDataScope->scopedEmployeeId(auth()->user());
        $leadCounts = $this->leadStatusCounts($employeeId);
        $totalLeads = (int) ($leadCounts['total'] ?? 0);
        $assignedLeads = $this->assignedLeadCount($employeeId);
        $followUpCounts = $this->followUpCounts($employeeId);
        $whatsappMetrics = $this->whatsappMetrics();
        $emailMetrics = $this->emailMetrics();
        $smsMetrics = $this->smsMetrics();
        $safetyMetrics = $this->communicationSafetyMetrics();

        $reportParams = $employeeId ? ['employee_id' => $employeeId] : [];

        return [
            'total_leads' => $totalLeads,
            'new_leads' => (int) ($leadCounts['new_established'] ?? 0),
            'hot_leads' => (int) ($leadCounts['hot'] ?? 0),
            'warm_leads' => (int) ($leadCounts['warm'] ?? 0),
            'cold_leads' => (int) ($leadCounts['cold'] ?? 0),
            'pipeline_leads' => (int) ($leadCounts['pipeline'] ?? 0),
            'lost_leads' => (int) ($leadCounts['lost'] ?? 0),
            'calls_total' => $this->callsTotal($employeeId),
            'meetings_today' => $this->meetingsTodayCount($employeeId),
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
        ];
    }

    private function leadStatusCounts(?int $employeeId): array
    {
        $query = CaMaster::query();
        $this->employeeDataScope->scopeCaMasterQuery($query, $employeeId);

        $row = $query
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'Hot') as hot")
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'Warm') as warm")
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'Cold') as cold")
            ->selectRaw('COUNT(*) FILTER (WHERE status IN ('.$this->quotedList(self::PIPELINE_STATUSES).')) as pipeline')
            ->selectRaw("COUNT(*) FILTER (WHERE status IN ('Lost', 'Inactive')) as lost")
            ->selectRaw('COUNT(*) FILTER (WHERE is_newly_established = true) as new_established')
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

    private function assignedLeadCount(?int $employeeId): int
    {
        $query = LeadAssignmentEngine::query()->where('status', 'Active');
        $this->employeeDataScope->scopeLeadAssignmentQuery($query, $employeeId);

        return (int) $query->distinct()->count('ca_id');
    }

    private function followUpCounts(?int $employeeId): array
    {
        $query = FollowUp::query()->whereIn('status', self::OPEN_FOLLOWUP_STATUSES);
        $this->employeeDataScope->scopeFollowUpQuery($query, $employeeId);

        $row = $query
            ->selectRaw('COUNT(*) FILTER (WHERE DATE(scheduled_date) = CURRENT_DATE) as due_today')
            ->selectRaw('COUNT(*) FILTER (WHERE scheduled_date < CURRENT_DATE) as overdue')
            ->first();

        return [
            'due_today' => (int) ($row->due_today ?? 0),
            'overdue' => (int) ($row->overdue ?? 0),
        ];
    }

    private function whatsappMetrics(): array
    {
        $statusCounts = WaMessageLog::query()
            ->selectRaw("COUNT(*) FILTER (WHERE message_status = 'Delivered') as delivered")
            ->selectRaw("COUNT(*) FILTER (WHERE message_status = 'Failed') as failed")
            ->selectRaw("COUNT(*) FILTER (WHERE message_status = 'Queued') as queued")
            ->selectRaw('COUNT(*) as total')
            ->first();

        return [
            'campaigns_total' => WhatsAppCampaign::query()->count(),
            'messages_total' => (int) ($statusCounts->total ?? 0),
            'delivered' => (int) ($statusCounts->delivered ?? 0),
            'failed' => (int) ($statusCounts->failed ?? 0),
            'queued' => (int) ($statusCounts->queued ?? 0),
        ];
    }

    private function emailMetrics(): array
    {
        $statusCounts = EmailLog::query()
            ->selectRaw("COUNT(*) FILTER (WHERE email_status = 'Delivered') as delivered")
            ->selectRaw("COUNT(*) FILTER (WHERE email_status = 'Failed') as failed")
            ->selectRaw("COUNT(*) FILTER (WHERE email_status = 'Queued') as queued")
            ->selectRaw('COUNT(*) as total')
            ->first();

        return [
            'campaigns_total' => EmailCampaign::query()->count(),
            'messages_total' => (int) ($statusCounts->total ?? 0),
            'delivered' => (int) ($statusCounts->delivered ?? 0),
            'failed' => (int) ($statusCounts->failed ?? 0),
            'queued' => (int) ($statusCounts->queued ?? 0),
        ];
    }

    private function smsMetrics(): array
    {
        $statusCounts = SmsLog::query()
            ->selectRaw("COUNT(*) FILTER (WHERE sms_status = 'Delivered') as delivered")
            ->selectRaw("COUNT(*) FILTER (WHERE sms_status = 'Failed') as failed")
            ->selectRaw("COUNT(*) FILTER (WHERE sms_status = 'Queued') as queued")
            ->selectRaw("COUNT(*) FILTER (WHERE sms_status = 'Mapped') as mapped")
            ->selectRaw('COUNT(*) as total')
            ->first();

        $settings = SmsSetting::query()->first();

        return [
            'campaigns_total' => SmsCampaign::query()->count(),
            'messages_total' => (int) ($statusCounts->total ?? 0),
            'delivered' => (int) ($statusCounts->delivered ?? 0),
            'failed' => (int) ($statusCounts->failed ?? 0),
            'queued' => (int) ($statusCounts->queued ?? 0),
            'mapped' => (int) ($statusCounts->mapped ?? 0),
            'pending_campaigns' => SmsCampaign::query()->whereIn('status', ['Draft', 'Scheduled'])->count(),
            'mode' => $settings?->mode ?? SmsSetting::MODE_SIMULATION,
        ];
    }

    private function communicationSafetyMetrics(): array
    {
        $dndSkipped = $this->countSkippedLogs('dnd_optout');
        $noConsentSkipped = $this->countSkippedLogs('no_consent');

        return [
            'dnd_contacts' => DndManagement::query()->distinct('ca_id')->count('ca_id'),
            'consent_approved' => ConsentTracking::query()->where('consent_status', 'Yes')->count(),
            'consent_denied' => ConsentTracking::query()->where('consent_status', 'No')->count(),
            'skipped_due_to_dnd' => $dndSkipped,
            'skipped_due_to_no_consent' => $noConsentSkipped,
        ];
    }

    private function callsTotal(?int $employeeId): int
    {
        $query = FollowUp::query()->where('followup_type', 'Call');
        $this->employeeDataScope->scopeFollowUpQuery($query, $employeeId);

        return (int) $query->count();
    }

    private function meetingsTodayCount(?int $employeeId): int
    {
        $query = FollowUp::query()
            ->whereDate('scheduled_date', now()->toDateString())
            ->whereIn('followup_type', ['Demo Scheduled', 'Demo Completed', 'Meeting']);
        $this->employeeDataScope->scopeFollowUpQuery($query, $employeeId);

        return (int) $query->count();
    }

    private function countSkippedLogs(string $reason): int
    {
        $wa = WaMessageLog::query()
            ->where('message_status', 'Skipped')
            ->where('failed_reason', $reason)
            ->count();
        $email = EmailLog::query()
            ->where('email_status', 'Skipped')
            ->where('failed_reason', $reason)
            ->count();
        $sms = SmsLog::query()
            ->where('sms_status', 'Skipped')
            ->where('failed_reason', $reason)
            ->count();

        return $wa + $email + $sms;
    }

    private function quotedList(array $values): string
    {
        return collect($values)
            ->map(fn (string $value) => DB::getPdo()->quote($value))
            ->implode(', ');
    }
}
