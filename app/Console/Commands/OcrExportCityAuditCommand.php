<?php

namespace App\Console\Commands;

use App\Models\OcrDocument;
use App\Services\Ocr\OcrCityAuditService;
use Illuminate\Console\Command;

class OcrExportCityAuditCommand extends Command
{
    protected $signature = 'ocr:export-city-audit
        {document : OCR document ID}
        {--csv= : Output CSV path}';

    protected $description = 'Export OCR city audit rows to CSV';

    public function handle(OcrCityAuditService $audit): int
    {
        $id = (int) $this->argument('document');
        $document = OcrDocument::query()->find($id);
        if ($document === null) {
            $this->error("OCR document #{$id} not found.");

            return self::FAILURE;
        }

        $path = $this->option('csv')
            ?: storage_path('app/ocr-audits/document-'.$id.'-city-'.now()->format('Ymd-His').'.csv');
        @mkdir(dirname($path), 0755, true);
        $report = $audit->audit($document);
        $out = fopen($path, 'w');
        if ($out === false) {
            $this->error("Cannot write {$path}");

            return self::FAILURE;
        }
        $audit->writeCsv($out, $report);
        fclose($out);
        $this->info("Wrote {$path} (".$report['firm_count'].' rows)');

        return self::SUCCESS;
    }
}
