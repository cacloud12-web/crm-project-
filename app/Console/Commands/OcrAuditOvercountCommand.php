<?php

namespace App\Console\Commands;

use App\Models\OcrDocument;
use App\Services\Ocr\OcrOvercountAuditService;
use Illuminate\Console\Command;

class OcrAuditOvercountCommand extends Command
{
    protected $signature = 'ocr:audit-overcount
        {document : OCR document ID}
        {--csv= : Optional CSV path for suspicious extra rows}
        {--expected=26000 : Approximate expected firm count for overcount delta}';

    protected $description = 'Classify OCR parsed rows to explain firm-count overcount (city headings, orphans, duplicates)';

    public function handle(OcrOvercountAuditService $audit): int
    {
        $id = (int) $this->argument('document');
        $document = OcrDocument::query()->find($id);
        if ($document === null) {
            $this->error("OCR document #{$id} not found.");

            return self::FAILURE;
        }

        $csv = $this->option('csv')
            ?: storage_path('app/ocr-audits/document-'.$id.'-overcount-'.now()->format('Ymd-His').'.csv');

        $report = $audit->audit($document, $csv);
        if (isset($report['expected_firm_count_approx'])) {
            $report['expected_firm_count_approx'] = (int) $this->option('expected');
            $report['overcount_vs_expected_approx'] = max(0, (int) $report['candidate_records'] - (int) $report['expected_firm_count_approx']);
        }

        $this->info("OCR overcount audit — document #{$id} ({$document->original_filename})");
        $this->line('source_pages='.$report['source_pages']);
        $this->line('ocr_output_shards='.$report['ocr_output_shards']);
        $this->line('raw_blocks='.$report['raw_blocks']);
        $this->line('candidate_records='.$report['candidate_records']);
        $this->line('rows_with_firm_name='.$report['rows_with_firm_name']);
        $this->line('valid_complete_records='.$report['valid_complete_records']);
        $this->line('final_unique_valid_records='.$report['final_unique_valid_records']);
        $this->line('unique_firm_city_pairs='.$report['unique_firm_city_pairs']);
        $this->line('invalid_noise_records='.$report['invalid_noise_records']);
        $this->line('exact_source_duplicates='.$report['exact_source_duplicates']);
        $this->line('normalized_business_duplicates='.$report['normalized_business_duplicates']);
        $this->line('duplicate_bounding_boxes='.($report['duplicate_bounding_boxes'] ?? 0));
        $this->line('duplicate_source_rows='.($report['duplicate_source_rows'] ?? 0));
        $this->line('final_unique_firms='.$report['final_unique_valid_records']);
        $this->line('overcount_amount='.$report['overcount_vs_expected_approx']);
        $this->line('overcount_vs_rows_with_firm='.$report['overcount_vs_rows_with_firm']);
        $recon = $report['reconciliation'] ?? [];
        $this->line('reconciliation_balances='.(! empty($recon['equation_balances']) ? 'yes' : 'no'));
        $this->newLine();
        $this->info('Category counts:');
        foreach ($report['categories'] as $name => $count) {
            $this->line(sprintf('  %-36s %d', $name, $count));
        }
        $this->newLine();
        $this->info('CSV suspicious rows: '.$report['csv_path']);

        return self::SUCCESS;
    }
}
