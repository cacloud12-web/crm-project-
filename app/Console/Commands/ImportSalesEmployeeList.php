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

    protected $description = 'Import an employee calling list and Auto Match against ca_masters (Sales Mapping tiers)';

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

        $this->info('Auto Match tiers: Firm+CA+City → Firm+Mobile → CA+Mobile → Normalized triple → Email.');
        $this->info('Auto-match only when exactly one unique Master candidate is above confidence threshold.');
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
                ['Duplicate', $result['duplicate'] ?? $result['already_existing']],
                ['Matched', $result['matched']],
                ['Needs review', $result['needs_review']],
                ['Unmatched', $result['unmatched']],
                ['Skipped', $result['skipped'] ?? $result['skipped_blank']],
                ['Rejected', $result['rejected'] ?? $result['failed']],
                ['Processing ms', $result['processing_ms'] ?? '—'],
            ]
        );
        $this->warn('No CA master identity/verification/OCR/Google fields were created, updated, or deleted.');

        return self::SUCCESS;
    }
}
