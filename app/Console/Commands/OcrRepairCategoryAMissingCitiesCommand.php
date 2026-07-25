<?php

namespace App\Console\Commands;

use App\Services\Ocr\OcrRepairCategoryAMissingCitiesService;
use Illuminate\Console\Command;
use Throwable;

class OcrRepairCategoryAMissingCitiesCommand extends Command
{
    protected $signature = 'ocr:repair-missing-cities-category-a
        {--dry-run : Report only (default unless --apply)}
        {--apply : Write city_id for Category A rows only}
        {--force : Required with --apply in production}
        {--classification= : Path to classification detail CSV (Category column)}
        {--cities-csv= : Optional cities CSV (city_id,city_name) instead of DB cities}
        {--baseline-missing= : Baseline missing-city count for before/after report (e.g. 26492)}
        {--chunk=200 : Transaction chunk size}
        {--limit=0 : Limit Category A rows processed (0 = all)}
        {--export= : CSV path for every planned/updated row}';

    protected $description = 'Repair Master city_id for classification Category A only (never B/C/D/E)';

    public function handle(OcrRepairCategoryAMissingCitiesService $repair): int
    {
        $apply = (bool) $this->option('apply');
        if ((bool) $this->option('dry-run')) {
            $apply = false;
        }

        if ($apply && app()->environment('production') && ! (bool) $this->option('force')) {
            $this->error('Refusing production --apply without --force.');

            return self::FAILURE;
        }

        if ($apply && ! $this->confirm(
            'Update ONLY city_id on Category A Masters from the classification CSV? B/C/D/E will not be touched.',
            false
        )) {
            $this->warn('Cancelled. No rows updated.');

            return self::SUCCESS;
        }

        $this->info($apply
            ? 'Category A missing-city repair — APPLY (city_id only)'
            : 'Category A missing-city repair — DRY-RUN (no writes)');
        $this->comment('Scope: classification Category A only. Abort on any ambiguous city mapping.');

        $baseline = $this->option('baseline-missing');
        $baselineMissing = $baseline !== null && $baseline !== '' ? (int) $baseline : null;

        try {
            $result = $repair->run([
                'apply' => $apply,
                'dry_run' => ! $apply,
                'classification' => $this->option('classification')
                    ?: storage_path('app/audits/ocr-linked-missing-cities-categories-prod-detail.csv'),
                'cities_csv' => $this->option('cities-csv') ?: null,
                'baseline_missing' => $baselineMissing,
                'chunk' => (int) ($this->option('chunk') ?? 200),
                'limit' => (int) ($this->option('limit') ?? 0),
                'export' => $this->option('export'),
            ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('======================================');
        $this->line('CATEGORY A MISSING-CITY REPAIR');
        $this->line('======================================');
        $this->table(['Metric', 'Value'], [
            ['Mode', $result['dry_run'] ? 'dry-run' : 'apply'],
            ['Current missing city', number_format((int) $result['current_missing_city'])],
            ['Recoverable now (Category A)', number_format((int) $result['recoverable_now'])],
            ['Remaining after Category A', number_format((int) $result['remaining_after_category_a'])],
            ['Would update (still missing in DB)', number_format((int) $result['would_update'])],
            ['Updated', number_format((int) $result['updated'])],
            ['Skipped already has city', number_format((int) $result['skipped_has_city'])],
            ['Skipped not in DB', number_format((int) $result['skipped_not_found'])],
            ['DB missing before', number_format((int) $result['before_missing'])],
            ['DB missing after', number_format((int) $result['after_missing'])],
            ['Export CSV', $result['export_path']],
            ['Audit JSON', $result['audit_json_path']],
        ]);

        $this->newLine();
        $this->line('Current missing city:');
        $this->line(number_format((int) $result['current_missing_city']));
        $this->line('Recoverable now:');
        $this->line(number_format((int) $result['recoverable_now']));
        $this->line('Remaining after Category A:');
        $this->line(number_format((int) $result['remaining_after_category_a']));

        if ($result['dry_run']) {
            $this->newLine();
            $this->comment('No database rows were modified.');
            $this->comment('To apply on production: php artisan ocr:repair-missing-cities-category-a --apply --force --cities-csv=... --baseline-missing=26492');
        }

        return ((int) ($result['errors'] ?? 0)) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
