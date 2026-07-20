<?php

namespace App\Jobs;

use App\Models\OcrDocument;
use App\Services\Ocr\OcrDocumentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CheckBatchOcrStatusJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public int $uniqueFor = 120;

    public function __construct(
        public readonly int $ocrDocumentId,
    ) {}

    public function uniqueId(): string
    {
        return 'ocr-batch-check-'.$this->ocrDocumentId;
    }

    public function backoff(): array
    {
        return [10, 20, 30, 60];
    }

    public function handle(OcrDocumentService $ocrDocumentService): void
    {
        try {
            $ocrDocumentService->checkBatchProcessing($this->ocrDocumentId);
        } catch (Throwable $exception) {
            \Illuminate\Support\Facades\Log::error('ocr.pipeline.job_failed', [
                'step' => 'batch_check_job',
                'ocr_document_id' => $this->ocrDocumentId,
                'error_message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        \Illuminate\Support\Facades\Log::error('ocr.pipeline.job_permanently_failed', [
            'step' => 'batch_check_job',
            'ocr_document_id' => $this->ocrDocumentId,
            'error_message' => $exception->getMessage(),
        ]);
    }
}
