<?php

namespace App\Console\Commands;

use App\Services\Bulk\BulkCaReferenceImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportCaReferenceCommand extends Command
{
    protected $signature = 'ca-reference:import
        {file : Absolute or relative path to CSV/XLSX}
        {--dry-run : Parse and reconcile without writing ca_firms/partners/addresses}
        {--chunk=1000 : Rows per transactional chunk (100-5000)}
        {--no-row-logs : Skip writing ca_reference_import_rows (faster large imports)}';

    protected $description = 'Import official CA reference Excel/CSV into ca_firms, ca_partners, ca_addresses (not OCR)';

    public function handle(BulkCaReferenceImportService $importer): int
    {
        $file = (string) $this->argument('file');
        if (! str_starts_with($file, DIRECTORY_SEPARATOR)) {
            $file = base_path($file);
        }

        $dryRun = (bool) $this->option('dry-run');
        $chunk = (int) $this->option('chunk');
        $persistRowLogs = ! (bool) $this->option('no-row-logs');

        $this->info($dryRun
            ? 'Dry-run: no writes to ca_firms / ca_partners / ca_addresses.'
            : 'Importing into ca_reference (chunked transactions)...');
        $this->line('File: '.$file);
        $this->line('Chunk size: '.$chunk);

        try {
            $result = $importer->import($file, $dryRun, $chunk, $persistRowLogs);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $rec = $result['reconciliation'];
        $this->newLine();
        $this->info('Reconciliation');
        $this->table(
            ['Metric', 'Count'],
            [
                ['batch_id', (string) ($rec['batch_id'] ?? 'n/a')],
                ['source_rows', (string) ($rec['source_rows'] ?? 0)],
                ['imported_firms', (string) ($rec['imported_firms'] ?? 0)],
                ['imported_ca_names', (string) ($rec['imported_ca_names'] ?? 0)],
                ['imported_cities', (string) ($rec['imported_cities'] ?? 0)],
                ['duplicates', (string) ($rec['duplicates'] ?? 0)],
                ['skipped', (string) ($rec['skipped'] ?? 0)],
                ['failed', (string) ($rec['failed'] ?? 0)],
                ['reused_firms', (string) ($rec['reused_firms'] ?? 0)],
                ['success_rows', (string) ($rec['success_rows'] ?? 0)],
                ['dry_run', ! empty($rec['dry_run']) ? 'yes' : 'no'],
            ],
        );

        if ($dryRun) {
            $this->warn('Dry-run complete. Re-run without --dry-run to write.');
        } else {
            $this->info('Import completed successfully.');
        }

        return self::SUCCESS;
    }
}
