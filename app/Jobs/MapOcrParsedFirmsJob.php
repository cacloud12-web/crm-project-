<?php

namespace App\Jobs;

use App\Models\OcrDocument;
use App\Services\Mapping\MasterDataMappingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the Master Data Mapping Engine against OCR staging firms.
 * Queued for larger documents; small sets may run inline from persist.
 */
class MapOcrParsedFirmsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $ocrDocumentId,
        public readonly ?int $actorId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'ocr-map-'.$this->ocrDocumentId;
    }

    public function handle(MasterDataMappingService $mappingService): void
    {
        $document = OcrDocument::query()->find($this->ocrDocumentId);
        if (! $document) {
            Log::warning('ocr.document.mapping_skipped_missing', ['ocr_document_id' => $this->ocrDocumentId]);

            return;
        }

        if ($document->parse_status !== 'completed') {
            Log::warning('ocr.document.mapping_skipped_parse_status', [
                'ocr_document_id' => $this->ocrDocumentId,
                'parse_status' => $document->parse_status,
            ]);

            return;
        }

        $document->update(['processing_progress' => 'Mapping to Master Data']);

        try {
            $stats = $mappingService->processOcrDocument($this->ocrDocumentId, $this->actorId);
            $structured = is_array($document->structured_data) ? $document->structured_data : [];
            $structured['mapping'] = [
                'processed' => (int) ($stats['processed'] ?? 0),
                'auto_created' => (int) ($stats['auto_created'] ?? 0),
                'auto_updated' => (int) ($stats['auto_updated'] ?? 0),
                'needs_review' => (int) ($stats['needs_review'] ?? 0),
                'conflicts' => (int) ($stats['conflicts'] ?? 0),
                'skipped' => (int) ($stats['skipped'] ?? 0),
                'mapped_at' => now()->toIso8601String(),
            ];
            $document->update([
                'structured_data' => $structured,
                'processing_progress' => 'Completed',
            ]);

            Log::info('ocr.document.mapped', [
                'ocr_document_id' => $this->ocrDocumentId,
                'stats' => collect($stats)->except('decisions')->all(),
            ]);
        } catch (Throwable $e) {
            Log::error('ocr.document.mapping_failed', [
                'ocr_document_id' => $this->ocrDocumentId,
                'error' => $e->getMessage(),
            ]);
            $document->update(['processing_progress' => 'Mapping failed — retry available']);

            throw $e;
        }
    }
}
