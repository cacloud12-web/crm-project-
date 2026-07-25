<?php

namespace App\Console\Commands;

use App\Services\Ocr\OcrMasterVsPdfFirmAuditService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only audit: ca_masters firms vs OCR PDF batch firm names.
 */
class OcrAuditMasterVsPdfFirmsCommand extends Command
{
    protected $signature = 'ocr:audit-master-vs-pdf-firms
        {--document= : Single OCR document id}
        {--documents= : Comma-separated OCR document ids}
        {--filenames= : Comma-separated original_filename values}
        {--batch=default : Use default ICAI prop+part PDF batch (west/south/north/east/central × prop/part)}
        {--all-ocr : Compare against firms from every ocr_documents row}
        {--include-deleted-masters : Include soft-deleted ca_masters}
        {--export= : CSV path (default storage/app/audits/master-vs-pdf-firms.csv)}';

    protected $description = 'Read-only audit comparing Master firm names to OCR PDF batch firm names';

    public function handle(OcrMasterVsPdfFirmAuditService $audit): int
    {
        $this->info('Master vs PDF firms audit (read-only). No database rows will be modified.');

        $documentIds = [];
        if ($this->option('document') !== null && $this->option('document') !== '') {
            $documentIds[] = (int) $this->option('document');
        }
        if ($this->option('documents') !== null && $this->option('documents') !== '') {
            foreach (explode(',', (string) $this->option('documents')) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $documentIds[] = (int) $part;
                }
            }
        }

        $filenames = [];
        if ($this->option('filenames') !== null && $this->option('filenames') !== '') {
            foreach (explode(',', (string) $this->option('filenames')) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $filenames[] = $part;
                }
            }
        }

        $batch = strtolower(trim((string) ($this->option('batch') ?? 'default')));
        $useDefaultBatch = $batch === 'default'
            && $documentIds === []
            && $filenames === []
            && ! (bool) $this->option('all-ocr');

        // Explicit --batch=default even with other empty filters.
        if ($batch === 'default' && $documentIds === [] && $filenames === [] && ! (bool) $this->option('all-ocr')) {
            $useDefaultBatch = true;
        }
        if ($batch !== '' && $batch !== 'default' && $batch !== 'none') {
            $this->warn("Unknown --batch={$batch}; use default, or omit and pass --document(s)/--filenames/--all-ocr.");
        }

        try {
            $report = $audit->audit([
                'document_ids' => $documentIds !== [] ? $documentIds : null,
                'filenames' => $filenames !== [] ? $filenames : null,
                'use_default_batch' => $useDefaultBatch,
                'all_ocr' => (bool) $this->option('all-ocr'),
                'include_deleted_masters' => (bool) $this->option('include-deleted-masters'),
                'export' => $this->option('export') ?: storage_path('app/audits/master-vs-pdf-firms.csv'),
            ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('OCR documents in batch');
        $this->table(
            ['ID', 'Filename', 'Status'],
            collect($report['documents'])->map(static fn ($d) => [
                $d['id'],
                $d['filename'],
                $d['status'],
            ])->all()
        );

        $t = $report['totals'];
        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total firms in Master', $t['master_firms']],
                ['Total firms extracted from PDFs', $t['pdf_firms']],
                ['Firms present in both', $t['both']],
                ['Firms present only in Master', $t['master_only']],
                ['Firms present only in PDFs', $t['pdf_only']],
                ['Union (unique normalized names)', $t['union']],
            ]
        );

        $this->info('CSV export: '.$report['export_path']);
        $this->comment('Rows written: '.$report['row_count']);
        $this->comment('Normalization: trim → strip punctuation → collapse spaces → uppercase.');

        return self::SUCCESS;
    }
}
