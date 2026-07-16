<?php

namespace App\Jobs;

use App\Models\OcrDocument;
use App\Services\Ocr\OcrStructurePersistService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
            return;
        }

        if ($document->parse_status === 'completed' && (int) $document->parsed_firm_count > 0) {
            return;
        }

        try {
            $persistService->parseAndPersist($document);
        } catch (Throwable) {
            // Failure is recorded on the OCR document parse_status.
        }
    }
}
