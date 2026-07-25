<?php

namespace App\Console\Commands;

use App\Services\Ocr\OcrRelinkExactOfflineMatchesService;
use Illuminate\Console\Command;
use Throwable;

class OcrRelinkExactOfflineMatchesCommand extends Command
{
    protected $signature = 'ocr:relink-exact-offline-matches
        {--dry-run : Report only (default unless --apply)}
        {--apply : Write source_ocr_row_id + source_ocr_document_id only}
        {--force : Required with --apply in production}
        {--matches-csv= : Path to no-ocr-link-offline-firm-matches.csv}
        {--chunk=200 : Transaction chunk size}
        {--limit=0 : Limit Exact rows (0 = all)}
        {--trust-csv-ids : Use CSV OCR IDs even if missing from local ocr_parsed_firms}
        {--skip-duplicate-ocr-ids : Drop Exact rows that share an OCR firm id instead of aborting}
        {--skip-already-linked-ocr-ids : Drop Exact rows whose OCR firm id is already linked elsewhere}
        {--export= : CSV audit path}
        {--rollback= : Rollback JSON from a prior apply (restores previous source_ocr_* only)}';

    protected $description = 'Relink Masters to OCR staging for Exact offline matches only (source_ocr_* fields; never city_id)';

    public function handle(OcrRelinkExactOfflineMatchesService $service): int
    {
        $rollback = $this->option('rollback');
        if (is_string($rollback) && $rollback !== '') {
            return $this->handleRollback($service, $rollback);
        }

        $apply = (bool) $this->option('apply');
        if ((bool) $this->option('dry-run')) {
            $apply = false;
        }

        if ($apply && app()->environment('production') && ! (bool) $this->option('force')) {
            $this->error('Refusing production --apply without --force.');

            return self::FAILURE;
        }

        if ($apply && ! $this->confirm(
            'Restore ONLY source_ocr_row_id + source_ocr_document_id for Exact matches? city_id and business fields will NOT change.',
            false
        )) {
            $this->warn('Cancelled. No rows updated.');

            return self::SUCCESS;
        }

        $this->info($apply
            ? 'Exact OCR relink — APPLY (source_ocr_* only)'
            : 'Exact OCR relink — DRY-RUN (no writes)');
        $this->comment('Input: Confidence=Exact only. Strong/Weak/No Match skipped.');
        $this->comment('Aborts if duplicate OCR row IDs would be assigned (unless --skip-duplicate-ocr-ids).');

        try {
            $result = $service->run([
                'apply' => $apply,
                'matches_csv' => $this->option('matches-csv')
                    ?: storage_path('app/audits/no-ocr-link-offline-firm-matches.csv'),
                'chunk' => (int) ($this->option('chunk') ?? 200),
                'limit' => (int) ($this->option('limit') ?? 0),
                'trust_csv_ids' => (bool) $this->option('trust-csv-ids'),
                'skip_duplicate_ocr_ids' => (bool) $this->option('skip-duplicate-ocr-ids'),
                'skip_already_linked_ocr_ids' => (bool) $this->option('skip-already-linked-ocr-ids'),
                'export' => $this->option('export'),
            ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Metric', 'Value'], [
            ['Mode', $result['dry_run'] ? 'dry-run' : 'apply'],
            ['Exact CSV rows', number_format((int) $result['exact_csv_rows'])],
            ['Eligible after resolve', number_format((int) $result['eligible'])],
            ['Would relink', number_format((int) $result['would_relink'])],
            ['Relinked', number_format((int) $result['relinked'])],
            ['Skipped already has link', number_format((int) $result['skipped_has_link'])],
            ['Skipped not in DB', number_format((int) $result['skipped_not_found'])],
            ['Skipped wrong source_id', number_format((int) $result['skipped_wrong_source'])],
            ['Skipped ineligible/unresolved', number_format((int) $result['skipped_ineligible'])],
            ['Skipped ambiguous OCR', number_format((int) ($result['skipped_ambiguous_ocr'] ?? 0))],
            ['Skipped duplicate OCR ids', number_format((int) ($result['skipped_duplicate_ocr_ids'] ?? 0))],
            ['Skipped already-linked OCR ids', number_format((int) ($result['skipped_already_linked_ocr_ids'] ?? 0))],
            ['Duplicate OCR id groups', number_format((int) ($result['duplicate_ocr_id_groups'] ?? 0))],
            ['Already-linked conflicts', number_format((int) ($result['already_linked_conflicts'] ?? 0))],
            ['CSV audit', $result['export_path']],
            ['JSON audit', $result['audit_json_path']],
            ['Rollback file', $result['rollback_path']],
        ]);

        if ($result['dry_run']) {
            $this->newLine();
            $this->comment('No database rows were modified.');
            $this->comment('Apply: php artisan ocr:relink-exact-offline-matches --apply --force [--skip-duplicate-ocr-ids]');
            $this->comment('After relink, re-run missing-city Category A audit/repair for newly linked Masters.');
        }

        return ((int) ($result['errors'] ?? 0)) > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function handleRollback(OcrRelinkExactOfflineMatchesService $service, string $path): int
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
            'Rollback OCR links from '.$path.'? Only restores source_ocr_* when still equal to applied values.',
            false
        )) {
            $this->warn('Cancelled.');

            return self::SUCCESS;
        }

        $this->info($apply ? 'OCR relink ROLLBACK — APPLY' : 'OCR relink ROLLBACK — DRY-RUN');

        try {
            $result = $service->rollback($path, $apply, (int) ($this->option('chunk') ?? 200));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Metric', 'Value'], [
            ['Mode', $result['dry_run'] ? 'dry-run' : 'apply'],
            ['Candidates', $result['candidates']],
            ['Would rollback', $result['would_rollback']],
            ['Rolled back', $result['rolled_back']],
            ['Skipped', $result['skipped']],
            ['Export', $result['export_path']],
        ]);

        return self::SUCCESS;
    }
}
