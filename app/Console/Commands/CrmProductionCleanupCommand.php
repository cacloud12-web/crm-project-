<?php

namespace App\Console\Commands;

use App\Services\Uat\UatResetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CrmProductionCleanupCommand extends Command
{
    protected $signature = 'crm:production-cleanup
                            {--force : Required to delete all dummy/sample transactional CRM data}';

    protected $description = 'Remove all dummy/sample transactional CRM data for production use (preserves users, roles, and master configuration)';

    public function handle(UatResetService $resetService): int
    {
        if (! $this->option('force')) {
            $this->error('This command permanently deletes all leads, employees, assignments, campaigns, demos, and related transactional data.');
            $this->line('Users, roles, permissions, and master configuration are preserved.');
            $this->line('Re-run with --force to continue.');

            return self::FAILURE;
        }

        $this->warn('Preparing CRM for production — removing all transactional data...');

        $report = $resetService->reset();

        $this->newLine();
        $this->info('=== Records Deleted ===');
        $deletedRows = collect($report['deleted'])
            ->filter(fn ($count) => $count > 0)
            ->map(fn ($count, $table) => [$table, $count])
            ->values()
            ->all();

        if ($deletedRows === []) {
            $this->line('No transactional rows were present (already clean).');
        } else {
            $this->table(['Module / Table', 'Rows Deleted'], $deletedRows);
        }

        $this->newLine();
        $this->info('=== Storage Cleanup ===');
        $this->table(
            ['Path', 'Files Removed'],
            collect($report['storage'])->map(fn ($count, $path) => [$path, $count])->values()->all(),
        );

        $this->newLine();
        $this->info('=== Remaining System Users (login preserved) ===');
        $this->table(
            ['Email', 'Name', 'Role'],
            collect($report['remaining_users'])->map(fn ($u) => [$u['email'], $u['name'], $u['crm_role']])->all(),
        );

        $this->newLine();
        $this->info('=== Remaining Employees ===');
        if ($report['remaining_employees'] === []) {
            $this->line('No employee records — add real employees via the Employees module.');
        } else {
            $this->table(
                ['Email', 'Name'],
                collect($report['remaining_employees'])->map(fn ($e) => [$e['email_id'], $e['name']])->all(),
            );
        }

        $this->newLine();
        $this->info('=== Remaining Master Configuration ===');
        $this->table(
            ['Table', 'Rows'],
            collect($report['remaining_config'])->map(fn ($count, $table) => [$table, $count])->values()->all(),
        );

        $this->newLine();
        $this->info('=== Dashboard Metrics (post-cleanup) ===');
        $counts = $report['verification']['transactional_counts'];
        $salesCount = $counts['sales_list_entries'] ?? 0;
        $revenue = Schema::hasTable('sales_list_entries')
            ? (float) DB::table('sales_list_entries')->sum('total_amount')
            : 0.0;
        $dashboardRows = [
            ['Total Leads', $counts['ca_masters'] ?? 0],
            ['Assigned Leads', $counts['lead_assignment_engines'] ?? 0],
            ["Today's Calls", $counts['call_logs'] ?? 0],
            ["Today's Follow-ups", $counts['follow_ups'] ?? 0],
            ['Demo Scheduled', $counts['demo_schedules'] ?? 0],
            ['Demo Completed', $counts['demo_results'] ?? 0],
            ['Purchased', $counts['purchased_customers'] ?? 0],
            ['Sales', $salesCount],
            ['Revenue', '₹'.number_format($revenue, 0)],
            ['Duplicate Attempts', $counts['duplicate_attempts'] ?? 0],
            ['Employee Productivity', $counts['employee_productivity_logs'] ?? 0],
        ];
        $this->table(['Metric', 'Value'], $dashboardRows);

        $this->newLine();
        $this->info('=== Database Integrity ===');
        $verification = $report['verification'];
        $checks = [
            ['All transactional tables empty', $verification['all_transactional_zero'] ? '✓' : '✗'],
            ['No orphan records', $verification['no_orphans'] ? '✓' : '✗'],
            ['Users preserved', $verification['users_preserved'] ? '✓' : '✗'],
            ['Config preserved', $verification['config_preserved'] ? '✓' : '✗'],
            ['Production ready', $verification['ready_for_e2e'] ? '✓' : '✗'],
        ];
        $this->table(['Check', 'Status'], $checks);

        if (! $verification['ready_for_e2e']) {
            $this->warn('Verification found issues. Review transactional_counts and orphan_records.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('CRM is clean and ready for production use.');
        $this->line('Hard refresh the Demo Calendar page (Ctrl+Shift+R) if cached demo rows still appear.');

        return self::SUCCESS;
    }
}
