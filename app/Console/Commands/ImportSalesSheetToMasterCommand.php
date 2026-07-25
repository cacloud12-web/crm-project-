<?php

namespace App\Console\Commands;

use App\Services\Bulk\SalesSheetMasterImportService;
use Illuminate\Console\Command;

class ImportSalesSheetToMasterCommand extends Command
{
    protected $signature = 'master:import-sales-sheet
                            {path? : Absolute or storage-relative CSV path}
                            {--dry-run : Parse/dedupe only, no DB writes}
                            {--apply : Write to ca_masters}
                            {--batch=250 : Mapping-engine batch size}';

    protected $description = 'Import a sales leads CSV into Master Data (fast, no duplicate masters/phones, empty-only merge)';

    public function handle(SalesSheetMasterImportService $service): int
    {
        $path = $this->argument('path')
            ?: storage_path('app/sales.import/CA CloudDesk Leads - sheet52.csv');

        if (! str_starts_with($path, DIRECTORY_SEPARATOR) && ! preg_match('#^[A-Za-z]:\\\\#', $path)) {
            $path = base_path($path);
            if (! is_file($path)) {
                $path = storage_path('app/'.$this->argument('path'));
            }
        }

        $apply = (bool) $this->option('apply');
        $dryRun = (bool) $this->option('dry-run') || ! $apply;

        if ($apply && $this->option('dry-run')) {
            $this->error('Use either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        $this->info(($dryRun ? 'DRY-RUN' : 'APPLY').' → '.$path);
        $this->line('DB: '.config('database.default'));

        $report = $service->import(
            $path,
            $dryRun,
            max(25, (int) $this->option('batch')),
            function (int $done, int $total, array $partial) {
                $this->line(sprintf(
                    '  batch %d/%d — created=%d merged=%d',
                    $done,
                    $total,
                    $partial['new_masters_created'] ?? 0,
                    $partial['existing_masters_merged'] ?? 0,
                ));
            },
        );

        $this->newLine();
        $this->info('=== IMPORT SUMMARY ===');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total CSV rows', $report['total_csv_rows']],
                ['Skipped invalid', $report['skipped_invalid']],
                ['Duplicate rows inside CSV removed', $report['duplicate_rows_inside_csv_removed']],
                ['Rows after intra-file dedupe', $report['rows_after_intra_dedupe']],
                ['Existing Masters merged', $report['existing_masters_merged']],
                ['New Masters created', $report['new_masters_created']],
                ['Skipped conflict/review', $report['skipped_conflict_or_review']],
                ['Skipped duplicate (engine)', $report['skipped_duplicate_engine']],
                ['Cities resolved', $report['city_resolved']],
                ['Cities unresolved', $report['city_unresolved']],
                ['Time (sec)', $report['time_seconds']],
                ['Avg rows/sec', $report['rows_per_sec']],
                ['Peak memory (MB)', $report['peak_memory_mb']],
            ],
        );

        if (! empty($report['city_unresolved_samples'])) {
            $this->warn('City unresolved samples:');
            foreach ($report['city_unresolved_samples'] as $sample) {
                $this->line('  - '.$sample['city'].' ('.$sample['reason'].')');
            }
        }

        $verification = $report['verification'] ?? [];
        if ($verification !== []) {
            $this->newLine();
            $this->info('=== VERIFICATION ===');
            $this->line('ok: '.(($verification['ok'] ?? false) ? 'YES' : 'NO'));
            $this->line('duplicate_normalized_mobile_groups: '.($verification['duplicate_normalized_mobile_groups'] ?? 'n/a'));
            foreach ($verification['failures'] ?? [] as $failure) {
                $this->error('FAIL: '.$failure);
            }
        }

        $out = storage_path('app/audits/sheet52-master-import-'.now()->format('Ymd_His').'.json');
        @mkdir(dirname($out), 0775, true);
        file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT));
        $this->line('Report: '.$out);

        if (! $dryRun && ! ($verification['ok'] ?? true)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
