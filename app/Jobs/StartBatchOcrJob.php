<?php

namespace App\Jobs;

use App\Models\OcrDocument;
use App\Services\Ocr\OcrDocumentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class StartBatchOcrJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function __construct(
        public readonly int $ocrDocumentId,
    ) {}

    public function uniqueId(): string
    {
        return 'ocr-batch-start-'.$this->ocrDocumentId;
    }

    public function handle(OcrDocumentService $ocrDocumentService): void
    {
        try {
            $ocrDocumentService->startBatchProcessing($this->ocrDocumentId);
        } catch (Throwable $exception) {
            \Illuminate\Support\Facades\Log::error('ocr.pipeline.job_failed', [
                'step' => 'batch_start_job',
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
        if (! $document || in_array($document->status, [OcrDocument::STATUS_COMPLETED, OcrDocument::STATUS_FAILED], true)) {
            return;
        }

        $document->update([
            'status' => OcrDocument::STATUS_FAILED,
            'error_code' => 'batch_start_failed',
            'error_message' => 'Unable to start batch OCR. Please retry or verify Cloud Storage configuration.',
            'processed_at' => now(),
        ]);
    }
}
