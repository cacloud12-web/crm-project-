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
 * Bulk Accept eligible Master CA OCR staging rows into ca_masters.
 */
class ApproveEligibleMasterCaJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 7200;

    public int $uniqueFor = 7200;

    public function __construct(
        public readonly int $ocrDocumentId,
        public readonly ?int $actorId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'ocr-master-ca-approve-eligible-'.$this->ocrDocumentId;
    }

    public function handle(MasterCaDirectImportService $importer): void
    {
        $document = OcrDocument::query()->find($this->ocrDocumentId);
        if (! $document || ! $document->isMasterCaImport()) {
            Log::warning('ocr.approve.pipeline', [
                'step' => 'bulk_eligible_job_skipped',
                'ocr_document_id' => $this->ocrDocumentId,
            ]);

            return;
        }

        $stats = $importer->approveAllEligible($document, $this->actorId);

        Log::info('ocr.approve.pipeline', [
            'step' => 'bulk_eligible_job_finished',
            'ocr_document_id' => $this->ocrDocumentId,
            'stats' => $stats,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('ocr.approve.pipeline', [
            'step' => 'bulk_eligible_job_failed',
            'ocr_document_id' => $this->ocrDocumentId,
            'error' => $exception?->getMessage(),
        ]);

        $document = OcrDocument::query()->find($this->ocrDocumentId);
        if ($document) {
            $document->update([
                'processing_progress' => 'Accept All Eligible failed — check logs and retry',
                'error_message' => $exception?->getMessage(),
            ]);
        }
    }
}
