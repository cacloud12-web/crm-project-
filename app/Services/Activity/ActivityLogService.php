<?php

namespace App\Services\Activity;

use App\Models\ActivityLog;
use App\Models\AssignmentHistory;
use App\Models\BulkAction;
use App\Models\CaMaster;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Services\Concerns\SearchesListings;
use App\Services\Notifications\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Request;

class ActivityLogService
{
    use SearchesListings;

    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public const MODULES = [
        'CA_MASTER',
        'EMPLOYEE_MASTER',
        'LEAD_ASSIGNMENT_ENGINE',
        'BULK_ACTIONS',
        'FOLLOW_UP_MANAGEMENT',
        'DEMO_CONFIRMATION',
        'REPORTS',
        'SECURITY',
        'WHATSAPP_SETTINGS',
    ];

    public const ACTIONS = [
        'Add Lead',
        'Update Lead',
        'Delete Lead',
        'Add Employee',
        'Update Employee',
        'Delete Employee',
        'Lead Assignment',
        'Bulk Assignment',
        'Bulk Import',
        'Bulk Export',
        'Bulk Status Update',
        'WhatsApp Campaign Create',
        'Campaign Created',
        'Email Campaign Create',
        'Follow-up Create',
        'Follow-up Update',
        'Follow-up Delete',
        'Follow-up Completed',
        'Follow-up Rescheduled',
        'Task Created',
        'Task Completed',
        'Reminder Generated',
        'Overdue Follow-up',
        'Call Created',
        'Demo Scheduled',
        'Demo Rescheduled',
        'Confirmation SMS Sent',
        'Confirmation SMS Skipped',
        'Customer Confirmed',
        'Customer Rejected',
        'Consent Add',
        'Consent Update',
        'DND Add',
        'DND Remove',
        'Campaign Skip',
        'Payload Generated',
        'Campaign Processed',
        'WhatsApp Settings Updated',
        'Template Selected',
        'Report Export',
        'OCR Document Uploaded',
        'OCR Processing Started',
        'OCR Completed',
        'OCR Failed',
        'OCR Retry Requested',
        'OCR Text Corrected',
        'OCR Original Viewed',
        'OCR Document Deleted',
        'OCR Firm Approved',
        'OCR Firm Linked',
        'Access Denied',
        'Login Failed',
        'Login Locked',
        'Login Success',
        'Approval Requested',
        'Approval Granted',
        'Approval Rejected',
    ];

    public function log(
        string $moduleName,
        string $action,
        ?string $recordId = null,
        ?string $description = null,
        ?string $performedBy = null,
        ?Carbon $at = null,
        mixed $beforeValue = null,
        mixed $afterValue = null,
        ?string $ipAddress = null,
    ): ActivityLog {
        $timestamp = $at ?? now();
        $performedBy = $this->resolvePerformer($performedBy);

        $log = ActivityLog::create([
            'performed_by' => $performedBy,
            'module_name' => $moduleName,
            'record_id' => $recordId,
            'action' => $action,
            'description' => $description,
            'before_value' => $this->encodeAuditValue($beforeValue),
            'after_value' => $this->encodeAuditValue($afterValue),
            'ip_address' => $this->resolveIpAddress($ipAddress),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $this->maybeNotifyActivityAlert($action, $description, $performedBy);

        return $log;
    }

    /**
     * Use explicit performer when provided; otherwise the authenticated CRM user.
     * Seeders, console, and background jobs should pass 'System' explicitly.
     */
    public function resolvePerformer(?string $explicit = null): string
    {
        if ($explicit !== null && $explicit !== '' && strcasecmp($explicit, 'System') !== 0) {
            return $explicit;
        }

        $user = auth()->user();

        if ($user) {
            return $user->name ?: $user->email ?: 'System';
        }

        return $explicit === 'System' ? 'System' : 'System';
    }

    public function encodeAuditValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);

        return $encoded === false ? (string) json_encode(['value' => (string) $value]) : $encoded;
    }

    public function resolveIpAddress(?string $explicit = null): ?string
    {
        if ($explicit !== null && $explicit !== '') {
            return $explicit;
        }

        if (! app()->runningInConsole()) {
            return Request::ip();
        }

        return null;
    }

    private function maybeNotifyActivityAlert(
        string $action,
        ?string $description,
        ?string $performedBy,
    ): void {
        $alertActions = config('notifications.activity_alert_actions', []);

        if (! in_array($action, $alertActions, true)) {
            return;
        }

        $this->notificationService->activityAlert(
            $action,
            $description ?? $action,
            $performedBy,
        );
    }

    public function search(array $params = []): array
    {
        $result = $this->searchListing(ActivityLog::query(), $params, 'activity_logs');

        return [
            'logs' => collect($result['items']),
            'pagination' => $result['pagination'],
            'meta' => $result['meta'],
            'filter_options' => $this->filterOptions(),
        ];
    }

    public function list(array $filters = []): array
    {
        if (! empty($filters['limit']) && empty($filters['page']) && empty($filters['per_page'])) {
            $query = ActivityLog::query()->orderByDesc('created_at');

            if (! empty($filters['module_name'])) {
                $query->where('module_name', $filters['module_name']);
            }
            if (! empty($filters['action'])) {
                $query->where('action', $filters['action']);
            }
            if (! empty($filters['date'])) {
                $query->whereDate('created_at', $filters['date']);
            }
            if (! empty($filters['user'])) {
                $query->where('performed_by', 'ilike', '%'.$filters['user'].'%');
            }

            $limit = min(max((int) $filters['limit'], 1), 500);

            return [
                'logs' => $query->limit($limit)->get(),
                'filter_options' => $this->filterOptions(),
            ];
        }

        return $this->search($filters);
    }

    private function filterOptions(): array
    {
        return [
            'modules' => ActivityLog::query()
                ->distinct()
                ->orderBy('module_name')
                ->pluck('module_name')
                ->values()
                ->all(),
            'actions' => ActivityLog::query()
                ->distinct()
                ->orderBy('action')
                ->pluck('action')
                ->values()
                ->all(),
            'users' => ActivityLog::query()
                ->distinct()
                ->orderBy('performed_by')
                ->pluck('performed_by')
                ->values()
                ->all(),
        ];
    }

    public function backfillFromExistingData(): int
    {
        if (ActivityLog::query()->exists()) {
            return ActivityLog::query()->count();
        }

        $rows = [];

        foreach (CaMaster::query()->orderBy('ca_id')->get(['ca_id', 'firm_name', 'created_at']) as $lead) {
            $rows[] = $this->row(
                'CA_MASTER',
                'Add Lead',
                $this->shortId((string) $lead->ca_id),
                $lead->firm_name ?: 'Lead #'.$lead->ca_id,
                $lead->created_at,
            );
        }

        foreach (Employee::query()->orderBy('employee_id')->get(['employee_id', 'name', 'created_at']) as $employee) {
            $rows[] = $this->row(
                'EMPLOYEE_MASTER',
                'Add Employee',
                (string) $employee->employee_id,
                $employee->name,
                $employee->created_at,
            );
        }

        $histories = AssignmentHistory::query()
            ->with(['caMaster:ca_id,firm_name', 'newEmployee:employee_id,name', 'previousEmployee:employee_id,name'])
            ->orderBy('assigned_at')
            ->get();

        foreach ($histories as $history) {
            $firm = $history->caMaster?->firm_name ?? 'Lead #'.$history->ca_id;
            $to = $history->newEmployee?->name ?? 'Employee #'.$history->new_employee_id;
            $from = $history->previousEmployee?->name;
            $description = $from
                ? "{$firm}: {$from} → {$to} ({$history->reason})"
                : "{$firm} → {$to} ({$history->reason})";

            $rows[] = $this->row(
                'LEAD_ASSIGNMENT_ENGINE',
                'Lead Assignment',
                $this->shortId((string) $history->ca_id),
                $description,
                $history->assigned_at,
            );
        }

        foreach (BulkAction::query()->where('action_type', 'ca_master_import')->orderBy('bulk_action_id')->get() as $bulkAction) {
            $description = sprintf(
                '%s — %d inserted, %d duplicates, %d failed out of %d rows',
                $bulkAction->file_name ?: 'CSV import',
                $bulkAction->success_records ?? 0,
                $bulkAction->duplicate_records ?? 0,
                $bulkAction->failed_records ?? 0,
                $bulkAction->total_records ?? 0,
            );

            $rows[] = $this->row(
                'BULK_ACTIONS',
                'Bulk Import',
                (string) $bulkAction->bulk_action_id,
                $description,
                $bulkAction->completed_at ?? $bulkAction->started_at ?? $bulkAction->created_at,
            );
        }

        foreach (BulkAction::query()->where('action_type', 'ca_master_export')->orderBy('bulk_action_id')->get() as $bulkAction) {
            $description = sprintf(
                '%s — %d records exported (%s)',
                $bulkAction->file_name ?: 'Export',
                $bulkAction->success_records ?? 0,
                strtoupper((string) ($bulkAction->export_format ?? 'csv')),
            );

            $rows[] = $this->row(
                'BULK_ACTIONS',
                'Bulk Export',
                (string) $bulkAction->bulk_action_id,
                $description,
                $bulkAction->completed_at ?? $bulkAction->started_at ?? $bulkAction->created_at,
            );
        }

        foreach (BulkAction::query()->where('action_type', 'ca_master_status_update')->orderBy('bulk_action_id')->get() as $bulkAction) {
            $description = sprintf(
                '%s — %d updated, %d skipped out of %d records',
                $bulkAction->file_name ?: 'Status update',
                $bulkAction->success_records ?? 0,
                $bulkAction->skipped_records ?? 0,
                $bulkAction->total_records ?? 0,
            );

            $rows[] = $this->row(
                'BULK_ACTIONS',
                'Bulk Status Update',
                (string) $bulkAction->bulk_action_id,
                $description,
                $bulkAction->completed_at ?? $bulkAction->started_at ?? $bulkAction->created_at,
            );
        }

        foreach (FollowUp::query()->with('caMaster:ca_id,firm_name')->orderBy('followup_id')->get() as $followUp) {
            $firm = $followUp->caMaster?->firm_name ?? 'Lead #'.$followUp->ca_id;
            $rows[] = $this->row(
                'FOLLOW_UP_MANAGEMENT',
                'Follow-up Create',
                (string) $followUp->followup_id,
                $followUp->followup_type.' · '.$firm,
                $followUp->created_at,
            );
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            ActivityLog::query()->insert($chunk);
        }

        return count($rows);
    }

    private function row(
        string $moduleName,
        string $action,
        ?string $recordId,
        ?string $description,
        mixed $at,
    ): array {
        $timestamp = $at ? Carbon::parse($at) : now();

        return [
            'performed_by' => 'System',
            'module_name' => $moduleName,
            'record_id' => $recordId,
            'action' => $action,
            'description' => $description,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    private function shortId(string $id): string
    {
        if (strlen($id) <= 8) {
            return $id;
        }

        return substr($id, 0, 4).'…'.substr($id, -2);
    }
}
