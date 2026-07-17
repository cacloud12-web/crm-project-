<?php

namespace App\Jobs;

use App\Exceptions\DocumentAi\DocumentAiConfigurationException;
use App\Exceptions\Ocr\OcrProviderException;
use App\Models\OcrDocument;
use App\Services\Ocr\OcrDocumentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
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
        Log::info('ocr.pipeline.step', [
            'step' => 'job_process_handle',
            'ocr_document_id' => $this->ocrDocumentId,
            'attempt' => $this->attempts(),
        ]);

        try {
            $ocrDocumentService->processQueuedDocument($this->ocrDocumentId);
        } catch (Throwable $exception) {
            Log::error('ocr.pipeline.job_failed', [
                'step' => 'job_process_handle',
                'ocr_document_id' => $this->ocrDocumentId,
                'error_message' => $exception->getMessage(),
                'exception' => $exception::class,
                'attempt' => $this->attempts(),
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
                'error_message' => mb_substr($exception->getMessage() ?: 'The document could not be processed. Please retry.', 0, 2000),
                'processing_progress' => 'Failed',
                'failed_at' => now(),
                'processed_at' => now(),
            ]);
        }

        Log::error('ocr.pipeline.job_permanently_failed', [
            'step' => 'job_process_failed',
            'ocr_document_id' => $this->ocrDocumentId,
            'error_code' => $document->error_code,
            'error_message' => $exception->getMessage(),
        ]);
    }
}
