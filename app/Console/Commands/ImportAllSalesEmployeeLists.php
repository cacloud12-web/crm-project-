<?php

namespace App\Console\Commands;

use App\Services\Mapping\SalesEmployeeListImportService;
use Illuminate\Console\Command;

class ImportAllSalesEmployeeLists extends Command
{
    protected $signature = 'sales-list:import-all
                            {--dir= : Optional directory (defaults to storage/app/sales-imports)}
                            {--force-reimport : Re-scan files but never duplicate rows or erase mapping decisions}
                            {--map=* : Optional basename:EMPLOYEE overrides, e.g. --map="RAHUL SALES LIST.csv:RAHUL"}';

    protected $description = 'Safely import every supported employee sales CSV from storage/app/sales-imports';

    public function __construct(
        private readonly SalesEmployeeListImportService $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dir = $this->option('dir')
            ? (string) $this->option('dir')
            : $this->importer->directory();

        if (! is_dir($dir)) {
            $this->error("Directory not found: {$dir}");

            return self::FAILURE;
        }

        $this->applyCliMaps();

        $files = $this->importer->discoverFiles($dir);
        $this->info('Scanning: '.$dir);
        $this->info('Discovered '.count($files).' supported file(s).');
        $this->info('Auto Match unchanged; no CA Master/Reference writes.');
        $this->newLine();

        if ($files === []) {
            $this->warn('No CSV files to import.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force-reimport');
        $rows = [];
        $totals = [
            'total_rows' => 0,
            'imported' => 0,
            'already_existing' => 0,
            'matched' => 0,
            'needs_review' => 0,
            'unmatched' => 0,
            'failed' => 0,
        ];

        foreach ($files as $path) {
            $base = basename($path);
            $this->line('→ '.$base);

            $result = $this->importer->importFile($path, null, $force);

            if ($result['status'] === 'failed') {
                $this->error('  Failed: '.($result['error'] ?? $result['reason'] ?? 'unknown'));
            } elseif ($result['status'] === 'skipped') {
                $this->warn('  Skipped: '.($result['reason'] ?? 'unknown'));
            } else {
                $this->info(
                    '  Imported '.$result['imported'].
                    ', already '.$result['already_existing'].
                    ', matched '.$result['matched'].
                    ', review '.$result['needs_review'].
                    ', unmatched '.$result['unmatched']
                );
            }

            $rows[] = [
                $result['file'],
                $result['employee'] ?? '—',
                $result['total_rows'],
                $result['imported'],
                $result['already_existing'],
                $result['matched'],
                $result['needs_review'],
                $result['unmatched'],
                $result['failed'],
                $result['status'],
            ];

            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) ($result[$key] ?? 0);
            }
        }

        $this->newLine();
        $this->table(
            ['File', 'Employee', 'Total Rows', 'Imported', 'Already Existing', 'Matched', 'Needs Review', 'Unmatched', 'Failed', 'Status'],
            $rows
        );

        $this->newLine();
        $this->info('Overall totals');
        $this->table(
            ['Metric', 'Count'],
            collect($totals)->map(fn ($v, $k) => [str_replace('_', ' ', ucfirst($k)), $v])->values()->all()
        );

        $this->warn('No CA master/reference record was created, updated, or deleted.');
        $failedAny = collect($rows)->contains(fn ($r) => ($r[9] ?? '') === 'failed');

        return $failedAny ? self::FAILURE : self::SUCCESS;
    }

    private function applyCliMaps(): void
    {
        $maps = $this->option('map') ?? [];
        if (! is_array($maps) || $maps === []) {
            return;
        }

        $existing = config('sales_imports.employee_map', []);
        if (! is_array($existing)) {
            $existing = [];
        }

        foreach ($maps as $entry) {
            $parts = explode(':', (string) $entry, 2);
            if (count($parts) !== 2) {
                $this->warn('Ignoring invalid --map entry: '.$entry);

                continue;
            }
            $file = trim($parts[0]);
            $employee = trim($parts[1]);
            if ($file === '' || $employee === '') {
                continue;
            }
            $existing[$file] = $employee;
        }

        config(['sales_imports.employee_map' => $existing]);
    }
}
