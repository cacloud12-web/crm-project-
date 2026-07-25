<?php

namespace App\Console\Commands;

use App\Services\Ocr\OcrRepairMissingCitiesService;
use Illuminate\Console\Command;
use Throwable;

class OcrRepairMissingCitiesCommand extends Command
{
    protected $signature = 'ocr:repair-missing-cities
        {--dry-run : Report only (default unless --apply)}
        {--apply : Write city_id only for Category A (exact/alias unique OCR→cities matches)}
        {--force : Required with --apply in production}
        {--limit=0 : Limit masters scanned (0 = all)}
        {--chunk=200 : Transaction chunk size}
        {--include-deleted : Include soft-deleted masters}
        {--ocr-linked-only : Only Masters with source_ocr_row_id (default true)}
        {--all-missing : Include Masters without OCR link (overrides --ocr-linked-only)}
        {--export= : CSV path for every decision}
        {--rollback= : Rollback JSON from a prior apply (restores previous city_id only)}';

    protected $description = 'Safely fill Master city_id from OCR when exactly one valid city match exists';

    public function handle(OcrRepairMissingCitiesService $repair): int
    {
        $rollback = $this->option('rollback');
        if (is_string($rollback) && $rollback !== '') {
            return $this->handleRollback($repair, $rollback);
        }

        $apply = (bool) $this->option('apply');
        if ((bool) $this->option('dry-run')) {
            $apply = false;
        }

        $ocrLinkedOnly = ! (bool) $this->option('all-missing');
        if ($this->option('ocr-linked-only')) {
            $ocrLinkedOnly = true;
        }

        if ($apply && app()->environment('production') && ! (bool) $this->option('force')) {
            $this->error('Refusing production --apply without --force.');

            return self::FAILURE;
        }

        if ($apply && ! $this->confirm(
            'Update ONLY city_id on OCR-linked Category A Masters with exactly one valid OCR→cities match? Firm/CA and other fields will not be touched.',
            false
        )) {
            $this->warn('Cancelled. No rows updated.');

            return self::SUCCESS;
        }

        $this->info($apply
            ? 'Repair missing cities — APPLY (city_id only, Category A)'
            : 'Repair missing cities — DRY-RUN (no writes)');
        $this->comment($ocrLinkedOnly
            ? 'Scope: OCR-linked only (source_ocr_row_id IS NOT NULL)'
            : 'Scope: ALL missing city_id rows');
        $this->comment('Skips ambiguous / missing OCR city / multi-city / low-confidence. Never overwrites existing city_id.');

        try {
            $counts = $repair->run([
                'apply' => $apply,
                'dry_run' => ! $apply,
                'limit' => (int) ($this->option('limit') ?? 0),
                'chunk' => (int) ($this->option('chunk') ?? 200),
                'include_deleted' => (bool) $this->option('include-deleted'),
                'ocr_linked_only' => $ocrLinkedOnly,
                'export' => $this->option('export'),
            ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $class = $counts['by_class'] ?? [];
        $this->table(['Metric', 'Value'], [
            ['Mode', ! empty($counts['apply']) ? 'apply' : 'dry-run'],
            ['Total missing city', number_format((int) ($counts['total_missing_city'] ?? 0))],
            ['OCR-linked missing', number_format((int) ($counts['ocr_linked_missing'] ?? 0))],
            ['Scanned (scope)', number_format((int) ($counts['scanned'] ?? 0))],
            ['Eligible Category A', number_format((int) ($counts['eligible_category_a'] ?? 0))],
            ['Would update', number_format((int) ($counts['would_update'] ?? 0))],
            ['Updated', number_format((int) ($counts['updated'] ?? 0))],
            ['Skipped', number_format((int) ($counts['skipped'] ?? 0))],
            ['success_pct (of scanned)', ($counts['success_pct'] ?? 0).'%'],
            ['class_A', number_format((int) ($class['A'] ?? 0))],
            ['class_B', number_format((int) ($class['B'] ?? 0))],
            ['class_C', number_format((int) ($class['C'] ?? 0))],
            ['class_D', number_format((int) ($class['D'] ?? 0))],
            ['class_E', number_format((int) ($class['E'] ?? 0))],
            ['skipped_ambiguous', number_format((int) ($counts['skipped_ambiguous'] ?? 0))],
            ['skipped_no_info', number_format((int) ($counts['skipped_no_info'] ?? 0))],
            ['skipped_locality', number_format((int) ($counts['skipped_locality'] ?? 0))],
            ['skipped_uncertain', number_format((int) ($counts['skipped_uncertain'] ?? 0))],
            ['CSV audit', $counts['export_path'] ?? ''],
            ['JSON audit', $counts['audit_json_path'] ?? ''],
            ['Rollback file', $counts['rollback_path'] ?? ''],
        ]);

        if (! $apply) {
            $this->newLine();
            $this->comment('No database rows were modified.');
            $this->comment('To apply (OCR-linked Category A only): php artisan ocr:repair-missing-cities --apply --force');
        }

        return ((int) ($counts['errors'] ?? 0)) > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function handleRollback(OcrRepairMissingCitiesService $repair, string $path): int
    {
        $apply = (bool) $this->option('apply');
        if ((bool) $this->option('dry-run')) {
            $apply = false;
        }

        if ($apply && app()->environment('production') && ! (bool) $this->option('force')) {
            $this->error('Refusing production rollback --apply without --force.');

            return self::FAILURE;
        }

        if ($apply && ! $this->confirm(
            'Rollback city_id from '.$path.'? Only restores city_id when still equal to the applied value.',
            false
        )) {
            $this->warn('Cancelled.');

            return self::SUCCESS;
        }

        $this->info($apply ? 'Missing-city repair ROLLBACK — APPLY' : 'Missing-city repair ROLLBACK — DRY-RUN');

        try {
            $result = $repair->rollback($path, $apply, (int) ($this->option('chunk') ?? 200));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Metric', 'Value'], [
            ['Mode', $result['dry_run'] ? 'dry-run' : 'apply'],
            ['Candidates', number_format((int) $result['candidates'])],
            ['Would rollback', number_format((int) $result['would_rollback'])],
            ['Rolled back', number_format((int) $result['rolled_back'])],
            ['Skipped', number_format((int) $result['skipped'])],
            ['Export', $result['export_path']],
        ]);

        return self::SUCCESS;
    }
}
