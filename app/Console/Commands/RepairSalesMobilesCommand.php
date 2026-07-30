<?php

namespace App\Console\Commands;

use App\Services\Bulk\SalesMobileRepairService;
use Illuminate\Console\Command;

class RepairSalesMobilesCommand extends Command
{
    protected $signature = 'master:repair-sales-mobiles
                            {path? : Sales CSV path (default sheet52)}
                            {--dry-run : Report only, no writes (default)}
                            {--apply : Apply empty-only mobile repairs}';

    protected $description = 'Recover Sales CSV mobiles onto Master records with empty primary mobile (never overwrite, no duplicate masters)';

    public function handle(SalesMobileRepairService $service): int
    {
        $path = $this->argument('path')
            ?: storage_path('app/sales.import/CA CloudDesk Leads - sheet52.csv');

        if (! str_starts_with($path, DIRECTORY_SEPARATOR) && ! preg_match('#^[A-Za-z]:\\\\#', (string) $path)) {
            $candidate = base_path($path);
            $path = is_file($candidate) ? $candidate : storage_path('app/'.$this->argument('path'));
        }

        $apply = (bool) $this->option('apply');
        $dryRun = (bool) $this->option('dry-run') || ! $apply;

        if ($apply && $this->option('dry-run')) {
            $this->error('Use either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        if (! is_file($path)) {
            $this->warn('CSV not found at '.$path.' — will still promote alternate→primary only.');
            $path = null;
        }

        $this->info(($dryRun ? 'DRY-RUN' : 'APPLY').' sales mobile repair');
        if ($path) {
            $this->line('CSV: '.$path);
        }

        $report = $service->repair($path, $dryRun);

        $this->table(['Metric', 'Before', 'After'], [
            ['empty_primary', $report['before']['empty_primary'], $report['after']['empty_primary']],
            ['empty_primary_with_alt', $report['before']['empty_primary_with_alt'], $report['after']['empty_primary_with_alt']],
            ['empty_primary_and_alt', $report['before']['empty_primary_and_alt'], $report['after']['empty_primary_and_alt']],
            ['empty_primary_with_remarks', $report['before']['empty_primary_with_remarks'], $report['after']['empty_primary_with_remarks']],
        ]);

        $this->info('Recovered: '.$report['recovered_count'].' | Skipped (sample): '.$report['skipped_count']);
        $this->line('Report: '.$report['report_path']);

        foreach (array_slice($report['recovered'], 0, 15) as $row) {
            $this->line(sprintf(
                '  ca_id=%s | %s | CSV=%s → Mobile=%s | %s',
                $row['ca_id'],
                $row['firm_name'] ?? '',
                $row['csv_mobile'] ?? '',
                $row['imported_mobile'] ?? '',
                $row['reason'] ?? ''
            ));
        }

        return self::SUCCESS;
    }
}
