<?php

namespace App\Console\Commands;

use App\Models\OcrDocument;
use App\Services\Ocr\OcrStructurePersistService;
use Illuminate\Console\Command;

class StructureOcrDocumentsCommand extends Command
{
    protected $signature = 'ocr:structure
                            {id? : OCR document ID to structure}
                            {--all : Structure every completed OCR document missing firms}
                            {--force : Re-structure even when firms already exist}';

    protected $description = 'Convert completed OCR text into structured firm/partner records';

    public function handle(OcrStructurePersistService $persistService): int
    {
        $query = OcrDocument::query()->where('status', OcrDocument::STATUS_COMPLETED);

        if ($id = $this->argument('id')) {
            $query->whereKey((int) $id);
        } elseif ($this->option('all')) {
            if (! $this->option('force')) {
                $query->where(function ($q) {
                    $q->whereNull('parse_status')
                        ->orWhere('parse_status', 'failed')
                        ->orWhere('parsed_firm_count', 0)
                        ->orWhereNull('parsed_firm_count');
                });
            }
        } else {
            $this->error('Provide an OCR document ID or use --all.');

            return self::FAILURE;
        }

        $count = 0;
        $query->orderBy('id')->chunkById(20, function ($documents) use ($persistService, &$count) {
            foreach ($documents as $document) {
                try {
                    $persistService->parseAndPersist($document);
                    $count++;
                    $this->line("Structured OCR #{$document->id} → {$document->fresh()->parsed_firm_count} firm(s)");
                } catch (\Throwable $exception) {
                    $this->warn("Failed OCR #{$document->id}: ".class_basename($exception));
                }
            }
        });

        $this->info("Done. Structured {$count} document(s).");

        return self::SUCCESS;
    }
}
