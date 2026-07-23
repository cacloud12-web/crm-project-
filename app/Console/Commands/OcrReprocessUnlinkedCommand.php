<?php

namespace App\Console\Commands;

use App\Services\Ocr\OcrReprocessUnlinkedService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Staging-only reprocess for unlinked OCR rows.
 * Default is dry-run. Never writes ca_masters / crm_ca_id / approvals.
 */
class OcrReprocessUnlinkedCommand extends Command
{
    protected $signature = 'ocr:reprocess-unlinked
        {--all : Process all unlinked rows}
        {--document= : Limit to one OCR document id}
        {--category= : Limit to Phase-2 primary category / issue code}
        {--dry-run : Report planned changes without writing (default unless --apply-safe-only)}
        {--apply-safe-only : Apply safe staging-only corrections (never Master / crm_ca_id)}
        {--chunk=500 : Chunk size for DB iteration}
        {--limit=0 : Stop after N analyzed rows (0 = all)}
        {--actor= : Optional actor user id for audit rows}';

    protected $description = 'Reprocess unlinked OCR staging rows (dry-run by default; staging-only apply with --apply-safe-only)';

    public function handle(OcrReprocessUnlinkedService $service): int
    {
        $applySafe = (bool) $this->option('apply-safe-only');
        $dryRun = ! $applySafe;
        if ($this->option('dry-run')) {
            $dryRun = true;
            $applySafe = false;
        }

        if (! $this->option('all')
            && ($this->option('document') === null || $this->option('document') === '')
            && ($this->option('category') === null || $this->option('category') === '')) {
            $this->error('Specify --all and/or --document= and/or --category=.');

            return self::FAILURE;
        }

        $mode = $dryRun ? 'DRY-RUN (no staging writes)' : 'APPLY-SAFE-ONLY (staging fields only; no Master / crm_ca_id)';
        $this->info('OCR reprocess unlinked — '.$mode);

        try {
            $report = $service->reprocess([
                'all' => (bool) $this->option('all'),
                'document' => $this->option('document') !== null && $this->option('document') !== ''
                    ? (int) $this->option('document')
                    : null,
                'category' => $this->option('category'),
                'dry_run' => $dryRun,
                'apply_safe_only' => $applySafe && ! $dryRun,
                'chunk' => (int) ($this->option('chunk') ?? 500),
                'limit' => (int) ($this->option('limit') ?? 0),
                'actor' => $this->option('actor') !== null && $this->option('actor') !== ''
                    ? (int) $this->option('actor')
                    : null,
            ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $totals = $report['totals'];
        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['dry_run', $totals['dry_run'] ? 'yes' : 'no'],
                ['apply_safe_only', $totals['apply_safe_only'] ? 'yes' : 'no'],
                ['analyzed', $totals['analyzed']],
                ['would_change', $totals['would_change']],
                ['applied', $totals['applied']],
                ['errors', $totals['errors']],
            ]
        );

        $this->newLine();
        $this->info('Category breakdown');
        $rows = [];
        foreach ($report['categories'] as $category => $bucket) {
            if ((int) ($bucket['rows_analyzed'] ?? 0) === 0) {
                continue;
            }
            $rows[] = [
                $category,
                $bucket['rows_analyzed'],
                $bucket['would_change_parsed_ca'],
                $bucket['would_move_ca_to_address'],
                $bucket['would_recover_city'],
                $bucket['would_suggest_derived_ca'],
                $bucket['would_become_verified'],
                $bucket['would_remain_needs_review'],
                $bucket['errors'],
            ];
        }
        $this->table(
            [
                'Category',
                'Rows Analyzed',
                'Would Change Parsed CA',
                'Would Move CA→Address',
                'Would Recover City',
                'Would Suggest Derived CA',
                'Would Become Verified',
                'Would Remain Needs Review',
                'Errors',
            ],
            $rows
        );

        return self::SUCCESS;
    }
}
