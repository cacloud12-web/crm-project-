<?php

namespace App\Console\Commands;

use App\Services\Mapping\SalesEmployeeListImportService;
use Illuminate\Console\Command;

class ImportSalesEmployeeList extends Command
{
    protected $signature = 'sales-list:import
                            {file : CSV file path}
                            {--employee= : Employee name, for example ANKIT}
                            {--force-reimport : Re-scan file but never duplicate rows or erase mapping decisions}
                            {--replace : Deprecated destructive wipe — refused; use --force-reimport}';

    protected $description = 'Import an employee calling list and Auto Match against CA Reference (firm + city)';

    public function __construct(
        private readonly SalesEmployeeListImportService $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('replace')) {
            $this->error('--replace is disabled because it deleted mapping decisions. Use --force-reimport instead.');

            return self::FAILURE;
        }

        $filePath = $this->importer->resolveFilePath((string) $this->argument('file'));
        if (! is_file($filePath)) {
            $this->error("CSV file not found: {$filePath}");

            return self::FAILURE;
        }

        $this->info('Auto Match rule: exact normalized firm + city against CA Reference (exactly one hit).');
        $this->info('Reading CSV: '.basename($filePath));

        $result = $this->importer->importFile(
            $filePath,
            $this->option('employee') ? (string) $this->option('employee') : null,
            (bool) $this->option('force-reimport')
        );

        if ($result['status'] === 'skipped') {
            $this->warn('Skipped: '.($result['reason'] ?? 'unknown reason'));
            if ($result['employee']) {
                $this->line('Employee: '.$result['employee']);
            }

            return self::SUCCESS;
        }

        if ($result['status'] === 'failed') {
            $this->error('Import failed: '.($result['error'] ?? $result['reason'] ?? 'unknown error'));

            return self::FAILURE;
        }

        $this->info('Employee: '.($result['employee'] ?? '—'));
        if ($result['import_batch_id']) {
            $this->info('Import batch: #'.$result['import_batch_id']);
        }

        $this->newLine();
        $this->info('Employee list import finished.');
        $this->table(
            ['Result', 'Rows'],
            [
                ['Total rows', $result['total_rows']],
                ['Imported', $result['imported']],
                ['Already existing', $result['already_existing']],
                ['Matched', $result['matched']],
                ['Needs review', $result['needs_review']],
                ['Unmatched', $result['unmatched']],
                ['Failed', $result['failed']],
                ['Skipped blank', $result['skipped_blank']],
            ]
        );
        $this->warn('No CA master/reference record was created, updated, or deleted.');

        return self::SUCCESS;
    }
}
