<?php

namespace App\Services\Uat;

use App\Services\Cache\CrmCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class UatResetService
{
    /**
     * Transactional tables cleared in FK-safe order (children before parents).
     *
     * @var list<string>
     */
    private const TRANSACTIONAL_TABLES = [
        'email_attachments',
        'email_inbound_messages',
        'email_threads',
        'email_sync_logs',
        'crm_notification_reads',
        'crm_notifications',
        'sales_list_edit_histories',
        'sales_list_entries',
        'demo_schedule_history',
        'demo_reminders',
        'demo_results',
        'demo_reschedule_logs',
        'demo_confirmations',
        'demo_schedules',
        'demo_provider_leaves',
        'call_logs',
        'purchased_customers',
        'workflow_communication_logs',
        'follow_up_reminders',
        'follow_up_histories',
        'follow_up_reschedule_logs',
        'tasks',
        'follow_ups',
        'assignment_histories',
        'lead_assignment_engines',
        'employee_assignments',
        'lead_views',
        'lead_phone_numbers',
        'lead_quality_histories',
        'lead_research_logs',
        'lead_actions',
        'lead_lockings',
        'approval_requests',
        'consent_trackings',
        'dnd_management',
        'duplicate_attempt_logs',
        'duplicate_attempts',
        'import_duplicate_logs',
        'employee_productivity_logs',
        'yearly_employee_target_audits',
        'employee_calendar_days',
        'yearly_employee_targets',
        'daily_employee_target_audits',
        'daily_employee_targets',
        'employee_leaves',
        'wa_message_logs',
        'email_logs',
        'sms_logs',
        'whatsapp_campaigns',
        'email_campaigns',
        'sms_campaigns',
        'bulk_action_logs',
        'bulk_actions',
        'activity_logs',
        'saved_listing_filters',
        'lead_filter_preferences',
        'login_email_change_requests',
        'admin_dashboard_metrics',
        'throttle_logs',
        'api_rate_limits',
        'failed_queues',
        'queue_logs',
        'queue_jobs',
        'bounce_handlings',
        'spam_protections',
        'master_mapping_decisions',
        'ocr_parsed_members',
        'ocr_parsed_firms',
        'ocr_documents',
        'ca_masters',
    ];

    /**
     * Master / config tables that must remain untouched.
     *
     * @var list<string>
     */
    private const PRESERVED_CONFIG_TABLES = [
        'states',
        'cities',
        'source_leads',
        'team_size_masters',
        'role_masters',
        'reason_masters',
        'rating_masters',
        'notification_masters',
        'template_masters',
        'crm_settings',
        'email_settings',
        'email_templates',
        'sms_settings',
        'sms_templates',
        'whatsapp_settings',
        'message_templates',
        'follow_up_sequence_configs',
        'bulk_import_mapping_templates',
        'crm_roles',
        'crm_permissions',
        'crm_role_permissions',
        'crm_template_variables',
        'demo_providers',
        'company_holidays',
        'company_holiday_years',
        'retry_logics',
        'data_encryption_keys',
        'user_access_controls',
    ];

    public function __construct(
        private readonly CrmCacheService $cacheService,
    ) {}

    /**
     * @return array{
     *     deleted: array<string, int>,
     *     storage: array<string, int>,
     *     remaining_users: list<array{email: string, name: string, crm_role: string}>,
     *     remaining_employees: list<array{email_id: string, name: string}>,
     *     remaining_config: array<string, int>,
     *     verification: array<string, mixed>,
     * }
     */
    public function reset(bool $preserveEmployees = false): array
    {
        $deleted = [];

        DB::transaction(function () use (&$deleted, $preserveEmployees): void {
            foreach (self::TRANSACTIONAL_TABLES as $table) {
                $deleted[$table] = $this->deleteAllFrom($table);
            }

            $deleted['employees'] = $preserveEmployees ? 0 : $this->deleteAllEmployees();
        });

        $storage = $this->clearUploadedFiles();
        $this->invalidateCaches();

        return [
            'deleted' => $deleted,
            'storage' => $storage,
            'remaining_users' => $this->remainingUsers(),
            'remaining_employees' => $this->remainingEmployees(),
            'remaining_config' => $this->remainingConfigCounts(),
            'verification' => $this->verify($preserveEmployees),
            'employees_preserved' => $preserveEmployees,
        ];
    }

    private function deleteAllFrom(string $table): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return (int) DB::table($table)->delete();
    }

    private function deleteAllEmployees(): int
    {
        if (! Schema::hasTable('employees')) {
            return 0;
        }

        return (int) DB::table('employees')->delete();
    }

    /**
     * @return array<string, int>
     */
    private function clearUploadedFiles(): array
    {
        $counts = [
            'bulk_exports' => 0,
            'report_exports' => 0,
            'email_attachments' => 0,
        ];

        $disk = Storage::disk('local');

        foreach (['bulk-exports', 'report-exports', 'email-attachments'] as $directory) {
            if (! $disk->exists($directory)) {
                continue;
            }

            $files = $disk->allFiles($directory);
            $key = str_replace('-', '_', $directory);
            $counts[$key] = count($files);

            $disk->deleteDirectory($directory);
        }

        return $counts;
    }

    private function invalidateCaches(): void
    {
        $this->cacheService->forgetMasterListings();
        $this->cacheService->forgetDashboardMetrics();
        $this->cacheService->forgetLeadSegmentCounts();
        $this->cacheService->forgetPipelineStageCounts();
        $this->cacheService->forgetEmployeeRankings();
        $this->cacheService->forgetActivityFeed();
        $this->cacheService->bumpReportCacheVersion();

        if (Schema::hasTable('employees')) {
            DB::table('employees')
                ->whereNotNull('user_id')
                ->pluck('employee_id')
                ->each(fn ($employeeId) => $this->cacheService->forgetEmployeeDashboard((int) $employeeId));
        }
    }

    /**
     * @return list<array{email: string, name: string, crm_role: string}>
     */
    private function remainingUsers(): array
    {
        if (! Schema::hasTable('users')) {
            return [];
        }

        return DB::table('users')
            ->orderBy('email')
            ->get(['email', 'name', 'crm_role'])
            ->map(fn ($row) => [
                'email' => (string) $row->email,
                'name' => (string) $row->name,
                'crm_role' => (string) ($row->crm_role ?? ''),
            ])
            ->all();
    }

    /**
     * @return list<array{email_id: string, name: string}>
     */
    private function remainingEmployees(): array
    {
        if (! Schema::hasTable('employees')) {
            return [];
        }

        return DB::table('employees')
            ->orderBy('email_id')
            ->get(['email_id', 'name'])
            ->map(fn ($row) => [
                'email_id' => (string) $row->email_id,
                'name' => (string) $row->name,
            ])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function remainingConfigCounts(): array
    {
        $counts = [];

        foreach (self::PRESERVED_CONFIG_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = (int) DB::table($table)->count();
            }
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function verify(bool $preserveEmployees = false): array
    {
        $zeroChecks = [
            'ca_masters' => $this->tableCount('ca_masters'),
            'follow_ups' => $this->tableCount('follow_ups'),
            'lead_assignment_engines' => $this->tableCount('lead_assignment_engines'),
            'assignment_histories' => $this->tableCount('assignment_histories'),
            'demo_schedules' => $this->tableCount('demo_schedules'),
            'demo_results' => $this->tableCount('demo_results'),
            'call_logs' => $this->tableCount('call_logs'),
            'purchased_customers' => $this->tableCount('purchased_customers'),
            'sales_list_entries' => $this->tableCount('sales_list_entries'),
            'email_campaigns' => $this->tableCount('email_campaigns'),
            'sms_campaigns' => $this->tableCount('sms_campaigns'),
            'whatsapp_campaigns' => $this->tableCount('whatsapp_campaigns'),
            'crm_notifications' => $this->tableCount('crm_notifications'),
            'duplicate_attempts' => $this->tableCount('duplicate_attempts'),
            'employee_productivity_logs' => $this->tableCount('employee_productivity_logs'),
            'daily_employee_targets' => $this->tableCount('daily_employee_targets'),
            'yearly_employee_targets' => $this->tableCount('yearly_employee_targets'),
            'employee_assignments' => $this->tableCount('employee_assignments'),
            'activity_logs' => $this->tableCount('activity_logs'),
            'admin_dashboard_metrics' => $this->tableCount('admin_dashboard_metrics'),
            'ocr_documents' => $this->tableCount('ocr_documents'),
        ];

        if (! $preserveEmployees) {
            $zeroChecks['employees'] = $this->tableCount('employees');
        }

        $orphans = [
            'assignments_without_lead' => $this->orphanCount(
                'lead_assignment_engines',
                'ca_id',
                'ca_masters',
                'ca_id',
            ),
            'followups_without_lead' => $this->orphanCount(
                'follow_ups',
                'ca_id',
                'ca_masters',
                'ca_id',
            ),
            'assignments_without_employee' => $this->orphanCount(
                'lead_assignment_engines',
                'employee_id',
                'employees',
                'employee_id',
            ),
        ];

        $usersPreserved = Schema::hasTable('users') && DB::table('users')->count() > 0;

        return [
            'transactional_counts' => $zeroChecks,
            'all_transactional_zero' => collect($zeroChecks)->every(fn ($count) => $count === 0),
            'orphan_records' => $orphans,
            'no_orphans' => collect($orphans)->every(fn ($count) => $count === 0),
            'users_preserved' => $usersPreserved,
            'config_preserved' => collect($this->remainingConfigCounts())->filter()->isNotEmpty(),
            'ready_for_e2e' => collect($zeroChecks)->every(fn ($count) => $count === 0)
                && collect($orphans)->every(fn ($count) => $count === 0)
                && $usersPreserved,
        ];
    }

    private function tableCount(string $table): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return (int) DB::table($table)->count();
    }

    private function orphanCount(
        string $childTable,
        string $childColumn,
        string $parentTable,
        string $parentColumn,
    ): int {
        if (! Schema::hasTable($childTable) || ! Schema::hasTable($parentTable)) {
            return 0;
        }

        return (int) DB::table($childTable)
            ->whereNotIn($childColumn, DB::table($parentTable)->select($parentColumn))
            ->count();
    }
}
