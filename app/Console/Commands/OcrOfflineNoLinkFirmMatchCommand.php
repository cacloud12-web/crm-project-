<?php

namespace App\Console\Commands;

use App\Services\Ocr\OcrOfflineNoLinkFirmMatchService;
use Illuminate\Console\Command;
use Throwable;

class OcrOfflineNoLinkFirmMatchCommand extends Command
{
    protected $signature = 'ocr:offline-match-no-ocr-link
        {--masters-csv= : Path to no-ocr-link-missing-city.csv}
        {--firms-csv= : Optional OCR firms CSV (id,firm_name,city,ocr_document_id); default = local DB}
        {--export= : Output CSV path}
        {--limit=0 : Limit masters processed (0 = all)}';

    protected $description = 'Read-only offline match of Masters lacking OCR link to ocr_parsed_firms (no DB writes, no production joins)';

    public function handle(OcrOfflineNoLinkFirmMatchService $matcher): int
    {
        $masters = $this->option('masters-csv')
            ?: storage_path('app/audits/no-ocr-link-missing-city.csv');
        $export = $this->option('export')
            ?: storage_path('app/audits/no-ocr-link-offline-firm-matches.csv');
        $firmsCsv = $this->option('firms-csv') ?: null;

        $this->info('Offline no-OCR-link firm match (READ-ONLY). No database updates.');
        $this->comment('Masters: '.$masters);
        $this->comment('OCR firms: '.($firmsCsv ?: 'local DB ocr_parsed_firms'));

        try {
            $report = $matcher->run([
                'masters_csv' => $masters,
                'firms_csv' => $firmsCsv,
                'use_local_db' => $firmsCsv === null || $firmsCsv === '',
                'export' => $export,
                'limit' => (int) ($this->option('limit') ?? 0),
            ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $c = $report['counts'];
        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['Masters scanned', $c['masters']],
            ['Exact', $c['Exact']],
            ['Strong', $c['Strong']],
            ['Weak', $c['Weak']],
            ['No Match', $c['No Match']],
            ['OCR firm rows indexed', $report['ocr_firm_rows']],
            ['OCR normalized keys', $report['ocr_index_keys']],
        ]);
        $this->info('CSV: '.$report['export_path']);

        return self::SUCCESS;
    }
}
