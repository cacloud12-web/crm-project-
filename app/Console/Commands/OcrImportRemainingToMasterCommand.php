<?php

namespace App\Console\Commands;

use App\Services\Ocr\OcrImportRemainingToMasterService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Import remaining unlinked OCR rows into Master (verified or Needs Verification).
 * Default: dry-run. Never force-accept all as verified.
 */
class OcrImportRemainingToMasterCommand extends Command
{
    protected $signature = 'ocr:import-remaining-to-master
        {--all : Process all unlinked OCR rows}
        {--document= : Limit to one OCR document id}
        {--dry-run : Report only (default unless --apply)}
        {--apply : Persist Master creates/links}
        {--actor= : Required with --apply}
        {--chunk=500 : Chunk size}
        {--limit=0 : Stop after N rows (0 = all)}
        {--verified-only : Only process rows eligible for verified Master}
        {--needs-verification-only : Only process Needs Verification creates}
        {--show-errors : Print error category breakdown (read-only)}
        {--error-limit=50 : Max sample rows across error categories}';

    protected $description = 'Import remaining unlinked OCR rows as Verified or Needs Verification Master (dry-run default)';

    public function handle(OcrImportRemainingToMasterService $service): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = ! $apply || (bool) $this->option('dry-run');
        if ((bool) $this->option('dry-run')) {
            $apply = false;
            $dryRun = true;
        }

        if (! $this->option('all')
            && ($this->option('document') === null || $this->option('document') === '')) {
            $this->error('Specify --all and/or --document=.');

            return self::FAILURE;
        }

        if ($apply && ($this->option('actor') === null || $this->option('actor') === '')) {
            $this->error('--apply requires --actor=');

            return self::FAILURE;
        }

        $this->info($dryRun
            ? 'OCR import remaining → Master — DRY-RUN (no writes)'
            : 'OCR import remaining → Master — APPLY');
        $this->warn('Unresolved rows become Needs Verification — never silently verified.');

        try {
            $report = $service->run([
                'all' => (bool) $this->option('all'),
                'document' => $this->option('document') !== null && $this->option('document') !== ''
                    ? (int) $this->option('document')
                    : null,
                'dry_run' => $dryRun,
                'apply' => $apply && ! $dryRun,
                'actor' => $this->option('actor') !== null && $this->option('actor') !== ''
                    ? (int) $this->option('actor')
                    : null,
                'chunk' => (int) ($this->option('chunk') ?? 500),
                'limit' => (int) ($this->option('limit') ?? 0),
                'verified_only' => (bool) $this->option('verified-only'),
                'needs_verification_only' => (bool) $this->option('needs-verification-only'),
                'show_errors' => (bool) $this->option('show-errors'),
                'error_limit' => (int) ($this->option('error-limit') ?? 50),
            ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $schema = $report['schema'] ?? [];
        if ($schema !== []) {
            $this->newLine();
            $this->line('Schema flags (ca_masters): '
                .'ocr_city_text='.(! empty($schema['ocr_city_text']) ? 'yes' : 'NO')
                .' source_ocr_row_id='.(! empty($schema['source_ocr_row_id']) ? 'yes' : 'NO')
                .' verification_status='.(! empty($schema['verification_status']) ? 'yes' : 'NO'));
        }

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['dry_run', ! empty($report['dry_run']) ? 'yes' : 'no'],
            ['scanned', $report['scanned'] ?? 0],
            ['revalidated → verified', $report['revalidated_verified'] ?? 0],
            ['revalidated → needs_review', $report['revalidated_needs_review'] ?? 0],
            ['Eligible verified rows', $report['eligible_verified_rows'] ?? 0],
            ['Needs Verification rows', $report['needs_verification_rows'] ?? 0],
            ['Would CREATE verified Master', $report['would_create_verified_master'] ?? 0],
            ['Would LINK verified Master', $report['would_link_verified_master'] ?? 0],
            ['Would CREATE Needs Verification Master', $report['would_create_needs_verification_master'] ?? 0],
            ['Would LINK Needs Verification Master', $report['would_link_needs_verification_master'] ?? 0],
            ['Would link existing / duplicates (total)', $report['would_link_existing'] ?? 0],
            ['Ambiguous rows', $report['ambiguous_rows'] ?? 0],
            ['Noise rows skipped', $report['noise_rows_skipped'] ?? 0],
            ['Invalid rows skipped', $report['invalid_rows_skipped'] ?? 0],
            ['Created verified (apply)', $report['created_verified'] ?? 0],
            ['Created Needs Verification (apply)', $report['created_needs_verification'] ?? 0],
            ['Linked existing (apply)', $report['linked_existing'] ?? 0],
            ['Errors', $report['errors'] ?? 0],
        ]);

        if ((bool) $this->option('show-errors') || (int) ($report['errors'] ?? 0) > 0) {
            $this->renderErrorReport($report, (bool) $this->option('show-errors'));
        }

        if ($dryRun) {
            $this->newLine();
            $this->comment('Dry-run complete. No Master writes. Review counts before --apply.');
            $this->line('  ./php artisan ocr:import-remaining-to-master --all --apply --actor=ID');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderErrorReport(array $report, bool $showDetails): void
    {
        $categories = $report['error_categories'] ?? [];
        if ($categories === []) {
            if ($showDetails) {
                $this->newLine();
                $this->info('No errors to display.');
            }

            return;
        }

        $this->newLine();
        $this->warn('Error categories');
        $rows = [];
        foreach ($categories as $cat) {
            $rows[] = [
                $cat['category'] ?? 'unknown',
                $cat['count'] ?? 0,
                $cat['sqlstate'] ?? '',
                implode(',', $cat['sample_row_ids'] ?? []),
                $cat['origin'] ?? '',
                mb_substr((string) ($cat['message'] ?? ''), 0, 120),
            ];
        }
        usort($rows, static fn ($a, $b) => ($b[1] <=> $a[1]));
        $this->table(
            ['Error category', 'Count', 'SQLSTATE', 'Sample row IDs', 'Origin (file:line)', 'Exception/message'],
            $rows
        );

        if (! $showDetails) {
            $this->comment('Re-run with --show-errors --error-limit=50 for firm/CA/city samples.');

            return;
        }

        $this->newLine();
        $this->warn('Error samples (firm / CA / city / validation / exception)');
        $detailRows = [];
        foreach ($categories as $cat) {
            foreach ($cat['samples'] ?? [] as $sample) {
                $detailRows[] = [
                    $sample['category'] ?? ($cat['category'] ?? ''),
                    $sample['id'] ?? '',
                    mb_substr((string) ($sample['firm'] ?? ''), 0, 40),
                    mb_substr((string) ($sample['ca'] ?? ''), 0, 30),
                    mb_substr((string) ($sample['city'] ?? ''), 0, 20),
                    mb_substr((string) ($sample['validation_reason'] ?? ''), 0, 40),
                    mb_substr((string) ($sample['exception'] ?? ''), 0, 80),
                ];
            }
        }
        if ($detailRows === []) {
            foreach (array_slice($report['error_samples'] ?? [], 0, 50) as $sample) {
                $detailRows[] = [
                    $sample['category'] ?? '',
                    $sample['id'] ?? '',
                    mb_substr((string) ($sample['firm'] ?? ''), 0, 40),
                    mb_substr((string) ($sample['ca'] ?? ''), 0, 30),
                    mb_substr((string) ($sample['city'] ?? ''), 0, 20),
                    mb_substr((string) ($sample['validation_reason'] ?? ''), 0, 40),
                    mb_substr((string) ($sample['exception'] ?? ''), 0, 80),
                ];
            }
        }
        $this->table(
            ['Error category', 'Row ID', 'Firm', 'CA', 'City', 'Validation reason', 'Exception/message'],
            $detailRows
        );
        $payloadRows = [];
        foreach ($categories as $cat) {
            foreach ($cat['samples'] ?? [] as $sample) {
                $payloadRows[] = [
                    $sample['id'] ?? '',
                    $sample['plan_action'] ?? '',
                    $sample['plan_bucket'] ?? '',
                    $sample['data_quality_issue'] ?? '',
                    implode(',', $sample['payload_keys'] ?? []),
                    $sample['sqlstate'] ?? '',
                ];
            }
        }
        if ($payloadRows !== []) {
            $this->newLine();
            $this->warn('Error payload context');
            $this->table(
                ['Row ID', 'Plan action', 'Bucket', 'Quality issue', 'Payload keys', 'SQLSTATE'],
                $payloadRows
            );
        }
    }
}
