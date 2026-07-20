<?php

namespace App\Console\Commands;

use App\Services\Ocr\OcrSelectedTransferService;
use Illuminate\Console\Command;
use Throwable;

class OcrImportSelectedCommand extends Command
{
    protected $signature = 'ocr:import-selected
        {package : Path to export package directory}
        {--uploaded-by= : Live user id to set as uploaded_by on imported documents}
        {--chunk=500 : Insert chunk size}
        {--dry-run : Validate package without importing}
        {--resume : Resume a partially imported batch}
        {--force : Required in production to import}';

    protected $description = 'Import a selected OCR transfer package into live MySQL with new IDs and relationship remapping';

    public function handle(OcrSelectedTransferService $transfer): int
    {
        if (app()->environment('production') && ! $this->option('force') && ! $this->option('dry-run')) {
            $this->error('Refusing production import without --force.');

            return self::FAILURE;
        }

        $uploadedBy = (int) $this->option('uploaded-by');
        if ($uploadedBy <= 0 && ! $this->option('dry-run')) {
            $this->error('--uploaded-by is required for import.');

            return self::FAILURE;
        }

        $chunk = max(50, (int) $this->option('chunk'));
        $package = (string) $this->argument('package');
        $dryRun = (bool) $this->option('dry-run');
        $resume = (bool) $this->option('resume');

        $this->info(($dryRun ? 'Dry run: ' : '').'Importing OCR package from '.$package);

        try {
            $summary = $transfer->import(
                $package,
                $uploadedBy,
                $chunk,
                $dryRun,
                $resume,
                $this->output,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Metric', 'Value'], [
            ['batch_id', $summary['batch_id'] ?? '—'],
            ['documents_imported', $summary['documents_imported'] ?? ($summary['would_import']['documents'] ?? 0)],
            ['firms_imported', $summary['firms_imported'] ?? ($summary['would_import']['firms'] ?? 0)],
            ['members_imported', $summary['members_imported'] ?? ($summary['would_import']['members'] ?? 0)],
            ['document_id_map_count', count($summary['document_id_map'] ?? [])],
            ['firm_id_map_count', count($summary['firm_id_map'] ?? [])],
            ['dry_run', $dryRun ? 'yes' : 'no'],
        ]);

        if ($dryRun) {
            $this->comment('Dry run complete. No data was imported.');
        } else {
            $this->info('Import complete.');
        }

        return self::SUCCESS;
    }
}
