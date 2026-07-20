<?php

namespace App\Jobs;

use App\Models\OcrDocument;
use App\Services\Ocr\OcrDocumentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class FinalizeBatchOcrResultJob implements ShouldBeUnique, ShouldQueue
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

    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return 'ocr-batch-finalize-'.$this->ocrDocumentId;
    }

    public function handle(OcrDocumentService $ocrDocumentService): void
    {
        try {
            $ocrDocumentService->finalizeBatchProcessing($this->ocrDocumentId);
        } catch (Throwable $exception) {
            \Illuminate\Support\Facades\Log::error('ocr.pipeline.job_failed', [
                'step' => 'batch_finalize_job',
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
        if (! $document || $document->status === OcrDocument::STATUS_COMPLETED) {
            return;
        }

        if ($document->status !== OcrDocument::STATUS_FAILED) {
            $document->update([
                'status' => OcrDocument::STATUS_FAILED,
                'error_code' => 'batch_finalize_failed',
                'error_message' => 'Batch OCR finished but results could not be saved. Please retry.',
                'processed_at' => now(),
            ]);
        }

        unset($exception);
    }
}
