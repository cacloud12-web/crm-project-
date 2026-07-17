<?php

namespace App\Jobs;

use App\Models\OcrDocument;
use App\Services\Ocr\OcrStructurePersistService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Structures completed OCR text into firm/partner records.
 */
class ParseOcrStructureJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $ocrDocumentId,
    ) {}

    public function uniqueId(): string
    {
        return 'ocr-structure-'.$this->ocrDocumentId;
    }

    public function handle(OcrStructurePersistService $persistService): void
    {
        $document = OcrDocument::query()->find($this->ocrDocumentId);
        if (! $document || ! $document->isCompleted()) {
            Log::warning('ocr.pipeline.step', [
                'step' => 'parse_job_skip',
                'ocr_document_id' => $this->ocrDocumentId,
                'reason' => ! $document ? 'missing' : 'not_completed',
            ]);

            return;
        }

        if ($document->parse_status === 'completed' && (int) $document->parsed_firm_count > 0) {
            return;
        }

        Log::info('ocr.pipeline.step', [
            'step' => 'parse_job_handle',
            'ocr_document_id' => $this->ocrDocumentId,
        ]);

        try {
            $persistService->parseAndPersist($document);
        } catch (Throwable $exception) {
            Log::error('ocr.pipeline.job_failed', [
                'step' => 'parse_job_handle',
                'ocr_document_id' => $this->ocrDocumentId,
                'error_message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $document = OcrDocument::query()->find($this->ocrDocumentId);
        if (! $document) {
            return;
        }

        $document->update([
            'parse_status' => 'failed',
            'error_code' => 'structure_parse_failed',
            'error_message' => mb_substr($exception->getMessage() ?: 'Structure parsing failed.', 0, 2000),
            'processing_progress' => 'Parsing failed — retry available',
        ]);
    }
}
