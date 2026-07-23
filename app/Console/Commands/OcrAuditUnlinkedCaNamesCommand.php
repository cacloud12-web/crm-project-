<?php

namespace App\Console\Commands;

use App\Services\Ocr\OcrUnlinkedCaNameAuditService;
use Illuminate\Console\Command;
use Throwable;

class OcrAuditUnlinkedCaNamesCommand extends Command
{
    protected $signature = 'ocr:audit-unlinked-ca-names
        {--document= : Limit to one OCR document id}
        {--category= : Filter by primary category or issue code}
        {--limit=0 : Stop after N matching rows (0 = all)}
        {--export= : Optional CSV export path}
        {--json : Print full JSON report}
        {--summary-only : Print only the category summary table}';

    protected $description = 'Read-only audit of unlinked OCR staging rows (crm_ca_id IS NULL) by CA-name failure category';

    public function handle(OcrUnlinkedCaNameAuditService $audit): int
    {
        $this->info('OCR unlinked CA-name audit (read-only). No rows will be updated.');

        try {
            $report = $audit->audit([
                'document' => $this->option('document') !== null && $this->option('document') !== ''
                    ? (int) $this->option('document')
                    : null,
                'category' => $this->option('category'),
                'limit' => (int) ($this->option('limit') ?? 0),
                'export' => $this->option('export'),
                'sample_limit' => 20,
            ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $totals = $report['totals'];
        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['total_unlinked_rows', $totals['total_unlinked']],
                ['categorized_rows', $totals['categorized']],
                ['uncategorized_rows', $totals['uncategorized']],
                ['invalid_json_rows', $totals['invalid_json']],
                ['missing_source_data_rows', $totals['missing_source_data']],
                ['safe_repair_candidate_rows', $totals['safe_repair_candidate']],
                ['manual_review_required_rows', $totals['manual_review_required']],
                ['emitted_after_filters', $totals['emitted']],
            ]
        );

        $this->newLine();
        $this->info('Category summary');
        $this->table(
            ['Category', 'Count', 'Share %', 'Safe repair candidate?', 'Needs manual review?'],
            collect($report['summary'])->map(fn ($row) => [
                $row['category'],
                $row['count'],
                $row['share_pct'],
                $row['safe_repair_candidate'],
                $row['needs_manual_review'],
            ])->all()
        );

        if ((bool) $this->option('summary-only')) {
            if (! empty($report['export_path'])) {
                $this->info('CSV export: '.$report['export_path']);
            }

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Sample rows (up to 20)');
        $this->table(
            ['Row ID', 'Doc ID', 'Firm', 'Raw CA', 'Parsed CA', 'City', 'Review', 'Match', 'Conf', 'Primary', 'Issues', 'Reason'],
            collect($report['samples'])->map(function ($row) {
                return [
                    $row['id'],
                    $row['ocr_document_id'],
                    $this->clip($row['firm_name'] ?? '', 28),
                    $this->clip($row['raw_ca_name'] ?? '', 24),
                    $this->clip($row['parsed_ca_name'] ?? '', 24),
                    $this->clip($row['city'] ?? '', 14),
                    $row['review_status'] ?? '',
                    $row['match_status'] ?? '',
                    $row['overall_confidence'] ?? '',
                    $row['primary_category'] ?? '',
                    $this->clip(implode('|', $row['issue_codes'] ?? []), 36),
                    $this->clip($row['match_reason'] ?? '', 40),
                ];
            })->all()
        );

        if (! empty($report['export_path'])) {
            $this->newLine();
            $this->info('CSV export: '.$report['export_path']);
        }

        $this->comment('Read-only complete. No OCR staging or CA master rows were modified.');

        return self::SUCCESS;
    }

    private function clip(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max - 1).'…';
    }
}
