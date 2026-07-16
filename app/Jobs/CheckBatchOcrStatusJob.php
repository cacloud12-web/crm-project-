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

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $ocrDocumentId,
    ) {}

    public function uniqueId(): string
    {
        return 'ocr-batch-check-'.$this->ocrDocumentId;
    }

    public function handle(OcrDocumentService $ocrDocumentService): void
    {
        try {
            $ocrDocumentService->checkBatchProcessing($this->ocrDocumentId);
        } catch (Throwable) {
            // Persisted on the OCR record when appropriate.
        }
    }

    public function failed(Throwable $exception): void
    {
        // Next delayed poll will be re-dispatched by the service when possible.
        unset($exception);
    }
}
