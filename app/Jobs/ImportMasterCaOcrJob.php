<?php

namespace App\Jobs;

use App\Models\OcrDocument;
use App\Services\Ocr\MasterCaDirectImportService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Direct Master CA load from OCR staging — never runs sales mapping.
 */
class ImportMasterCaOcrJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 3600;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $ocrDocumentId,
        public readonly ?int $actorId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'ocr-master-ca-import-'.$this->ocrDocumentId;
    }

    public function handle(MasterCaDirectImportService $importer): void
    {
        Log::info('ocr.pipeline.step', [
            'step' => 'master_ca_import_job',
            'ocr_document_id' => $this->ocrDocumentId,
        ]);

        $document = OcrDocument::withTrashed()->find($this->ocrDocumentId);
        if (! $document || $document->trashed()) {
            Log::info('ocr.pipeline.step', [
                'step' => 'master_ca_import_skipped_missing',
                'ocr_document_id' => $this->ocrDocumentId,
                'trashed' => (bool) ($document?->trashed()),
            ]);

            return;
        }

        try {
            $importer->processDocument($this->ocrDocumentId, $this->actorId);
        } catch (Throwable $exception) {
            Log::error('ocr.pipeline.job_failed', [
                'step' => 'master_ca_import_job',
                'ocr_document_id' => $this->ocrDocumentId,
                'error_code' => 'master_import_failed',
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
            'error_code' => 'master_import_failed',
            'error_message' => mb_substr($exception->getMessage() ?: 'Master CA import failed.', 0, 2000),
            'processing_progress' => 'Import failed — retry available',
        ]);
    }
}
