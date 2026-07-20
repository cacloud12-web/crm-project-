<?php

namespace App\Console\Commands;

use App\Models\OcrDocument;
use App\Services\Ocr\OcrCityAuditService;
use Illuminate\Console\Command;

class OcrAuditCityCommand extends Command
{
    protected $signature = 'ocr:audit-city {document : OCR document ID}';

    protected $description = 'Audit OCR city headings, assignments, missing/conflict rows';

    public function handle(OcrCityAuditService $audit): int
    {
        $id = (int) $this->argument('document');
        $document = OcrDocument::query()->find($id);
        if ($document === null) {
            $this->error("OCR document #{$id} not found.");

            return self::FAILURE;
        }

        $report = $audit->audit($document);
        $this->info("OCR city audit — #{$id} ({$document->original_filename})");
        $this->line('directory_profile='.$report['directory_profile']);
        $this->line('heading_count='.$report['heading_count']);
        $this->line('firm_count='.$report['firm_count']);
        $this->line('missing_city_count='.$report['missing_city_count']);
        $this->line('address_like_city_count='.$report['address_like_city_count']);
        $this->line('city_ca_conflicts='.count($report['city_ca_conflicts']));
        $this->line('rejected_all_caps_candidates='.$report['rejected_count']);
        $this->newLine();
        $this->line('Top detected headings:');
        foreach (array_slice($report['detected_headings'], 0, 25) as $h) {
            $this->line(sprintf(
                '  p%d  %-20s → %-20s (%s %.2f)',
                $h['page'],
                $h['raw_heading'],
                $h['canonical_city'],
                $h['evidence'],
                $h['confidence'],
            ));
        }
        if ($report['address_like_city_rows'] !== []) {
            $this->newLine();
            $this->warn('Address-like city samples:');
            foreach (array_slice($report['address_like_city_rows'], 0, 10) as $row) {
                $this->line('  #'.$row['row'].' city='.$row['canonical_city'].' firm='.$row['firm_name']);
            }
        }

        return self::SUCCESS;
    }
}
