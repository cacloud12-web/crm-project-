<?php

namespace App\Console\Commands;

use App\Services\Ocr\OcrNeedsReviewProposalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Propose Firm/CA/City repairs for needs_review rows from stored OCR.
 * Defaults to dry-run. Never calls Google. Never writes unless explicitly approved apply path (disabled by default safety).
 */
class OcrReprocessNeedsReviewCommand extends Command
{
    protected $signature = 'ocr:reprocess-needs-review
        {--dry-run : Report only (default; always on unless --apply is later approved)}
        {--document= : Limit to one OCR document id}
        {--ca-id= : Limit to one linked CA id}
        {--limit=0 : Max rows to scan (0 = all)}
        {--category= : Filter A|B|C|D|F}
        {--export= : CSV export path}
        {--chunk=200 : Chunk size (informational; service uses 200)}
        {--resume-from=0 : Resume from ocr_parsed_firms.id}
        {--only-parser-fix : Only A/F categories}
        {--include-complete-check : Include Category A stale-status repair proposals (default true)}
        {--audit-csv= : Optional prior audit CSV to pin categories by firm row}
        {--apply : DISABLED unless OCR_NEEDS_REVIEW_APPLY=1 — refused by default}';

    protected $description = 'Dry-run Needs Review reprocess from stored Document AI (no Google; no DB writes by default)';

    public function handle(OcrNeedsReviewProposalService $proposals): int
    {
        if ((bool) $this->option('apply')) {
            if (! filter_var(env('OCR_NEEDS_REVIEW_APPLY', false), FILTER_VALIDATE_BOOL)) {
                $this->error('--apply is blocked. Set OCR_NEEDS_REVIEW_APPLY=1 only after explicit approval.');

                return self::FAILURE;
            }
            $this->error('--apply is not enabled in this build. Dry-run only. Await explicit approval to implement writes.');

            return self::FAILURE;
        }

        $export = $this->option('export');
        if ($export === null || $export === '') {
            $export = storage_path('app/ocr-audits/needs-review-parser-fix-dryrun-'.now()->format('Ymd_His').'.csv');
        }

        $auditCategories = null;
        $auditCsv = $this->option('audit-csv');
        if (is_string($auditCsv) && $auditCsv !== '' && is_file($auditCsv)) {
            $auditCategories = $this->loadAuditCategories($auditCsv);
            $this->comment('Loaded audit categories: '.count($auditCategories));
        }

        $includeComplete = $this->option('include-complete-check');
        // Symfony treats --include-complete-check as true when present; default true when absent.
        if (! $this->input->hasParameterOption('--include-complete-check', true)
            && ! $this->input->hasParameterOption('--no-include-complete-check', true)) {
            $includeComplete = true;
        }

        $this->info('OCR needs-review reprocess — DRY-RUN (zero DB writes)');
        $this->comment('Google Document AI will NOT be called. Masters will NOT be written.');

        $writesBefore = $this->stagingWriteFingerprint();

        $result = $proposals->propose([
            'document' => $this->option('document') !== null && $this->option('document') !== ''
                ? (int) $this->option('document') : null,
            'ca_id' => $this->option('ca-id') !== null && $this->option('ca-id') !== ''
                ? (int) $this->option('ca-id') : null,
            'limit' => (int) ($this->option('limit') ?? 0),
            'category' => $this->option('category') ?: null,
            'resume_from' => (int) ($this->option('resume-from') ?? 0),
            'only_parser_fix' => (bool) $this->option('only-parser-fix'),
            'include_complete_check' => (bool) $includeComplete,
            'audit_categories' => $auditCategories,
        ]);

        $writesAfter = $this->stagingWriteFingerprint();
        if ($writesBefore !== $writesAfter) {
            $this->error('SAFETY FAIL: staging fingerprint changed during dry-run.');

            return self::FAILURE;
        }
        $this->info('Dry-run write check: PASSED (no staging fingerprint change)');

        $this->writeCsv((string) $export, $result['rows']);
        $t = $result['totals'];

        $this->newLine();
        $this->table(['Metric', 'Value'], [
            ['scanned', $t['scanned'] ?? 0],
            ['unchanged', $t['unchanged'] ?? 0],
            ['CA recovered', $t['ca_recovered'] ?? 0],
            ['cities recovered', $t['city_recovered'] ?? 0],
            ['complete after parsing', $t['complete_after'] ?? 0],
            ['still Needs Review', $t['still_needs_review'] ?? 0],
            ['conflicts', $t['conflicts'] ?? 0],
            ['errors', $t['errors'] ?? 0],
            ['manual override skipped', $t['manual_override_skipped'] ?? 0],
            ['Category A', $t['category_A'] ?? 0],
            ['Category B', $t['category_B'] ?? 0],
            ['Category C', $t['category_C'] ?? 0],
            ['Category D', $t['category_D'] ?? 0],
            ['Category F', $t['category_F'] ?? 0],
            ['automatically recoverable', $t['automatically_recoverable'] ?? 0],
            ['export', $export],
        ]);

        return ((int) ($t['errors'] ?? 0)) > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<int|string, string>
     */
    private function loadAuditCategories(string $path): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return [];
        }
        $header = fgetcsv($fh);
        if (! is_array($header)) {
            fclose($fh);

            return [];
        }
        $idx = array_flip($header);
        $firmKey = $idx['firm_row_id'] ?? $idx['Firm Row ID'] ?? null;
        // Prior audit CSV uses Document ID + Firm Name — prefer Category column with firm from DB match.
        // Full completeness CSV has no firm_row_id; map by Document ID|Firm Name when possible later.
        $catKey = $idx['Category'] ?? $idx['category'] ?? null;
        $docKey = $idx['Document ID'] ?? $idx['document_id'] ?? null;
        $nameKey = $idx['Firm Name'] ?? $idx['firm_name'] ?? $idx['current_firm_name'] ?? null;
        $out = [];
        while (($row = fgetcsv($fh)) !== false) {
            $cat = $catKey !== null ? strtoupper(trim((string) ($row[$catKey] ?? ''))) : '';
            if ($cat === '' || ! in_array($cat, ['A', 'B', 'C', 'D', 'E', 'F', 'G'], true)) {
                continue;
            }
            if ($firmKey !== null && ($row[$firmKey] ?? '') !== '') {
                $out[(int) $row[$firmKey]] = $cat;
                continue;
            }
            if ($docKey !== null && $nameKey !== null) {
                $key = ((int) ($row[$docKey] ?? 0)).'|'.mb_strtoupper(trim((string) ($row[$nameKey] ?? '')));
                $out[$key] = $cat;
            }
        }
        fclose($fh);

        return $out;
    }

    private function stagingWriteFingerprint(): string
    {
        $agg = DB::table('ocr_parsed_firms')
            ->where('match_status', 'needs_review')
            ->selectRaw("COUNT(*) as c, COALESCE(SUM(id),0) as s, COALESCE(MAX(updated_at),'') as u")
            ->first();

        return sha1(json_encode([
            'c' => $agg->c ?? 0,
            's' => (string) ($agg->s ?? 0),
            'u' => (string) ($agg->u ?? ''),
        ]));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeCsv(string $path, array $rows): void
    {
        $dir = dirname($path);
        if ($dir !== '' && $dir !== '.' && ! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            return;
        }
        $headers = [
            'ca_id', 'document_id', 'firm_row_id', 'page_number',
            'current_firm_name', 'proposed_firm_name',
            'current_ca_name', 'proposed_ca_name',
            'current_city', 'proposed_city',
            'current_status', 'proposed_status',
            'current_review_reason', 'proposed_review_reason',
            'extraction_method', 'evidence', 'confidence',
            'action', 'conflict', 'category',
        ];
        fputcsv($fh, $headers);
        foreach ($rows as $row) {
            fputcsv($fh, array_map(static fn ($h) => is_array($row[$h] ?? null)
                ? implode('|', $row[$h])
                : ($row[$h] ?? ''), $headers));
        }
        fclose($fh);
    }
}
