<?php

namespace App\Console\Commands;

use App\Services\Ocr\OcrSelectedTransferService;
use Illuminate\Console\Command;
use Throwable;

class OcrExportSelectedCommand extends Command
{
    protected $signature = 'ocr:export-selected
        {--documents= : Comma-separated OCR document IDs to export}
        {--dry-run : Validate and preview without writing files}';

    protected $description = 'Export selected completed OCR documents with parsed firms and members to an NDJSON transfer package';

  private const DEFAULT_DOCUMENT_IDS = [52, 53, 55, 56, 57, 58, 61, 62, 63, 64];

    public function handle(OcrSelectedTransferService $transfer): int
    {
        $documentIds = $this->parseDocumentIds();
        if ($documentIds === []) {
            $this->error('Provide at least one document id via --documents=52,53,...');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info(($dryRun ? 'Dry run: ' : '').'Exporting '.count($documentIds).' OCR document(s)...');
        $this->line('IDs: '.implode(', ', $documentIds));

        try {
            $result = $transfer->export($documentIds, $dryRun, $this->output);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $manifest = $result['manifest'];
        $this->newLine();
        $this->table(['Metric', 'Value'], [
            ['batch_id', $result['batch_id']],
            ['documents', (int) ($manifest['documents']['count'] ?? 0)],
            ['firms', (int) ($manifest['firms']['count'] ?? 0)],
            ['members', (int) ($manifest['members']['count'] ?? 0)],
            ['orphan_firms', (int) ($manifest['orphan_checks']['orphan_firms'] ?? 0)],
            ['orphan_members', (int) ($manifest['orphan_checks']['orphan_members'] ?? 0)],
            ['package_path', $dryRun ? '(not written)' : $result['path']],
        ]);

        if (! $dryRun) {
            $this->info('Export complete: '.$result['path']);
            $this->line('Manifest: '.$result['path'].'/manifest.json');
        } else {
            $this->comment('Dry run complete. No files were written.');
        }

        return self::SUCCESS;
    }

    /** @return list<int> */
    private function parseDocumentIds(): array
    {
        $raw = trim((string) $this->option('documents'));
        if ($raw === '') {
            return self::DEFAULT_DOCUMENT_IDS;
        }

        return collect(explode(',', $raw))
            ->map(fn ($id) => (int) trim($id))
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
