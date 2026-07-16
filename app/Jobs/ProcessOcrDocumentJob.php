<?php

namespace App\Jobs;

use App\Exceptions\DocumentAi\DocumentAiConfigurationException;
use App\Exceptions\Ocr\OcrProviderException;
use App\Models\OcrDocument;
use App\Services\Ocr\OcrDocumentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Entry job: routes OCR documents to online or batch processing.
 */
class ProcessOcrDocumentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    /** Unique lock expires so a crashed worker cannot block retries forever. */
    public int $uniqueFor = 180;

    public function backoff(): array
    {
        return [15, 30, 60];
    }

    public function __construct(
        public readonly int $ocrDocumentId,
    ) {}

    public function uniqueId(): string
    {
        return 'ocr-document-'.$this->ocrDocumentId;
    }

    public function handle(OcrDocumentService $ocrDocumentService): void
    {
        try {
            $ocrDocumentService->processQueuedDocument($this->ocrDocumentId);
        } catch (Throwable) {
            // Failures are persisted on the OCR record.
        }
    }

    public function failed(Throwable $exception): void
    {
        $document = OcrDocument::query()->find($this->ocrDocumentId);
        if (! $document || $document->status === OcrDocument::STATUS_COMPLETED) {
            return;
        }

        if ($exception instanceof DocumentAiConfigurationException) {
            return;
        }

        if ($exception instanceof OcrProviderException && ! $exception->retryable) {
            return;
        }

        if ($document->status !== OcrDocument::STATUS_FAILED) {
            $document->update([
                'status' => OcrDocument::STATUS_FAILED,
                'error_code' => 'queue_failed',
                'error_message' => 'The document could not be processed. Please verify the OCR configuration or retry.',
                'processing_progress' => 'Failed',
                'processed_at' => now(),
            ]);
        }
    }
}
