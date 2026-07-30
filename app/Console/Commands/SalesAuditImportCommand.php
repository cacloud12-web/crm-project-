<?php

namespace App\Console\Commands;

use App\Services\Bulk\SalesImportAuditService;
use Illuminate\Console\Command;
use Throwable;

class SalesAuditImportCommand extends Command
{
    protected $signature = 'sales:audit-import
                            {path? : Absolute or project-relative path to the sales CSV}
                            {--output= : Output directory for JSON/CSV reports (default: storage/app/audits)}';

    protected $description = 'Read-only sales CSV audit before import (no DB writes). Outputs JSON + CSV reports.';

    public function handle(SalesImportAuditService $audit): int
    {
        $path = $this->argument('path')
            ?: storage_path('app/sales.import/CA CloudDesk Leads - sheet52.csv');

        if (! str_starts_with($path, DIRECTORY_SEPARATOR) && ! preg_match('#^[A-Za-z]:\\\\#', (string) $path)) {
            $candidate = base_path($path);
            if (! is_file($candidate)) {
                $candidate = storage_path('app/'.$path);
            }
            if (! is_file($candidate)) {
                $candidate = storage_path('app/sales.import/'.$path);
            }
            $path = $candidate;
        }

        $outputDir = $this->option('output') ?: storage_path('app/audits');

        $this->info('Sales List Audit (READ-ONLY) — no database modifications.');
        $this->line('CSV: '.$path);
        $this->line('DB:  '.config('database.default').' (select-only)');
        $this->newLine();

        try {
            $report = $audit->audit($path, $outputDir);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $s = $report['stats'];

        $this->line('========== OVERALL ==========');
        $this->table(['Metric', 'Value'], [
            ['Total rows in the CSV', $s['overall']['total_rows']],
            ['Blank rows', $s['overall']['blank_rows']],
            ['Valid rows', $s['overall']['valid_rows']],
            ['Invalid rows', $s['overall']['invalid_rows']],
        ]);

        $this->line('========== CA INFORMATION ==========');
        $this->table(['Metric', 'Value'], [
            ['Total CA Names', $s['ca']['total']],
            ['Missing CA Names', $s['ca']['missing']],
            ['Duplicate CA Names', $s['ca']['duplicate']],
            ['Unique CA Names', $s['ca']['unique']],
        ]);

        $this->line('========== FIRM INFORMATION ==========');
        $this->table(['Metric', 'Value'], [
            ['Total Firm Names', $s['firm']['total']],
            ['Missing Firm Names', $s['firm']['missing']],
            ['Duplicate Firm Names', $s['firm']['duplicate']],
            ['Unique Firm Names', $s['firm']['unique']],
        ]);

        $this->line('========== MOBILE NUMBERS ==========');
        $this->table(['Metric', 'Value'], [
            ['Total Mobile Numbers', $s['mobile']['total']],
            ['Missing Mobile Numbers', $s['mobile']['missing']],
            ['Invalid Mobile Numbers', $s['mobile']['invalid']],
            ['Duplicate Mobile Numbers', $s['mobile']['duplicate']],
            ['Existing Mobiles in ca_masters', $s['mobile']['existing']],
            ['New Mobiles not in CRM', $s['mobile']['new']],
        ]);

        $this->line('========== ALTERNATE MOBILE ==========');
        $this->table(['Metric', 'Value'], [
            ['Present', $s['alternate']['present']],
            ['Missing', $s['alternate']['missing']],
            ['Duplicate', $s['alternate']['duplicate']],
        ]);

        $this->line('========== EMAIL ==========');
        $this->table(['Metric', 'Value'], [
            ['Total Email IDs', $s['email']['total']],
            ['Missing Emails', $s['email']['missing']],
            ['Invalid Email Format', $s['email']['invalid']],
            ['Duplicate Emails', $s['email']['duplicate']],
            ['Existing Emails in ca_masters', $s['email']['existing']],
            ['New Emails', $s['email']['new']],
        ]);

        $this->line('========== CITY ==========');
        $this->table(['Metric', 'Value'], [
            ['Total Cities', $s['city']['total']],
            ['Missing Cities', $s['city']['missing']],
            ['Unique Cities', $s['city']['unique']],
            ['Cities found in cities table', $s['city']['found']],
            ['Cities not found in cities table', $s['city']['not_found']],
            ['Unknown city names (distinct)', $s['city']['unknown_city_count']],
        ]);
        if (! empty($s['city']['unknown_cities'])) {
            $unknownRows = [];
            foreach (array_slice($s['city']['unknown_cities'], 0, 25, true) as $name => $count) {
                $unknownRows[] = [$name, $count];
            }
            $this->warn('Unknown cities (top 25):');
            $this->table(['City', 'Rows'], $unknownRows);
        }

        $this->line('========== REMARKS ==========');
        $this->table(['Metric', 'Value'], [
            ['Remark columns', $s['remarks']['remark_columns']],
            ['Remark column names', implode(', ', $s['remarks']['remark_column_names'])],
            ['Rows with at least one remark', $s['remarks']['rows_with_remarks']],
            ['Rows with no remarks', $s['remarks']['rows_without_remarks']],
            ['Total remark values', $s['remarks']['total_remark_values']],
        ]);

        $this->line('========== EMPLOYEE ==========');
        $this->line('Total Employees: '.$s['employee']['total_employees']);
        $empRows = [];
        foreach ($s['employee']['leads_per_employee'] as $name => $count) {
            $empRows[] = [$name, $count];
        }
        $this->table(['Employee', 'Leads'], $empRows);

        $this->line('========== EXISTING CRM MATCH ==========');
        $this->table(['Metric', 'Value'], [
            ['Matched by Mobile', $s['crm_match']['matched_by_mobile']],
            ['Matched by Email', $s['crm_match']['matched_by_email']],
            ['Matched by Firm Name', $s['crm_match']['matched_by_firm_name']],
            ['Matched by CA Name', $s['crm_match']['matched_by_ca_name']],
            ['Completely New Leads', $s['crm_match']['completely_new_leads']],
        ]);

        $this->line('========== IMPORT SUMMARY ==========');
        $this->table(['Metric', 'Value'], [
            ['New Leads', $s['import_summary']['new_leads']],
            ['Existing Leads', $s['import_summary']['existing_leads']],
            ['Duplicate Rows', $s['import_summary']['duplicate_rows']],
            ['Rows Ready to Import', $s['import_summary']['rows_ready_to_import']],
            ['Rows Requiring Manual Review', $s['import_summary']['rows_requiring_manual_review']],
            ['Rows That Will Be Skipped', $s['import_summary']['rows_that_will_be_skipped']],
        ]);

        $this->newLine();
        $this->info('Reports written (read-only audit):');
        $this->line('  JSON:         '.$report['outputs']['json']);
        $this->line('  Rows CSV:     '.$report['outputs']['rows_csv']);
        $this->line('  Summary CSV:  '.$report['outputs']['summary_csv']);
        $this->line('Time: '.$report['time_seconds'].'s');

        return self::SUCCESS;
    }
}
