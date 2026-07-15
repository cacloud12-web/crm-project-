<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hard wipe of CRM transactional / dummy data.
 * Keeps: Super Admin + System Admin users, RBAC, states/cities,
 * lookup masters, templates, integration settings.
 *
 * Run: php artisan db:seed --class=WipeTransactionalCrmDataSeeder --force
 */
class WipeTransactionalCrmDataSeeder extends Seeder
{
    /** @var list<string> */
    private const KEEP_USER_EMAILS = [
        'superadmin@ca.local',
        'admin@ca.local',
    ];

    /** @var list<string> */
    private const WIPE_TABLES = [
        'lead_phone_numbers',
        'lead_views',
        'lead_research_logs',
        'lead_quality_histories',
        'lead_actions',
        'lead_lockings',
        'lead_filter_preferences',
        'call_logs',
        'assignment_histories',
        'lead_assignment_engines',
        'employee_assignments',
        'follow_up_histories',
        'follow_up_reminders',
        'follow_up_reschedule_logs',
        'follow_ups',
        'demo_reminders',
        'demo_confirmations',
        'demo_reschedule_logs',
        'demo_results',
        'demo_schedule_history',
        'demo_schedules',
        'sales_list_edit_histories',
        'sales_list_entries',
        'purchased_customers',
        'ocr_documents',
        'duplicate_attempt_logs',
        'duplicate_attempts',
        'import_duplicate_logs',
        'bulk_action_logs',
        'bulk_actions',
        'wa_message_logs',
        'email_logs',
        'sms_logs',
        'email_attachments',
        'email_inbound_messages',
        'email_threads',
        'email_sync_logs',
        'workflow_communication_logs',
        'whatsapp_campaigns',
        'email_campaigns',
        'sms_campaigns',
        'crm_notification_reads',
        'crm_notifications',
        'activity_logs',
        'employee_productivity_logs',
        'daily_employee_target_audits',
        'daily_employee_targets',
        'yearly_employee_target_audits',
        'yearly_employee_targets',
        'employee_calendar_days',
        'employee_leaves',
        'company_holidays',
        'company_holiday_years',
        'tasks',
        'approval_requests',
        'consent_trackings',
        'dnd_management',
        'saved_listing_filters',
        'login_email_change_requests',
        'throttle_logs',
        'queue_logs',
        'api_rate_limits',
        'failed_queues',
        'admin_dashboard_metrics',
        'ca_masters',
        'employees',
        'crm_user_permission_overrides',
        'sessions',
        'password_reset_tokens',
        'jobs',
        'job_batches',
        'failed_jobs',
        'cache',
        'cache_locks',
    ];

    public function run(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $counts = [];

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        try {
            foreach (self::WIPE_TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $before = (int) DB::table($table)->count();
                if ($before === 0) {
                    continue;
                }

                try {
                    DB::table($table)->truncate();
                } catch (\Throwable) {
                    DB::table($table)->delete();
                }

                $counts[$table] = $before;
            }

            if (Schema::hasTable('users')) {
                $counts['users'] = (int) DB::table('users')
                    ->whereNotIn('email', self::KEEP_USER_EMAILS)
                    ->delete();
            }

            DB::table('users')->where('email', 'superadmin@ca.local')->update([
                'crm_role' => 'super_admin',
                'is_active' => 1,
                'name' => 'Super Admin',
            ]);
            DB::table('users')->where('email', 'admin@ca.local')->update([
                'crm_role' => 'admin',
                'is_active' => 1,
            ]);
        } finally {
            if ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        Cache::flush();

        $this->command?->info('Transactional CRM data wiped (no demo reseed).');
        $this->command?->info('Kept users: '.implode(', ', self::KEEP_USER_EMAILS));
        if ($counts !== []) {
            $this->command?->table(
                ['Table', 'Rows removed'],
                collect($counts)->map(fn ($n, $t) => [$t, $n])->values()->all()
            );
        }

        $this->command?->info(
            'Remaining — leads: '.DB::table('ca_masters')->count()
            .', employees: '.DB::table('employees')->count()
            .', users: '.DB::table('users')->count()
        );
    }
}
