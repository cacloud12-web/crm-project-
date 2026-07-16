<?php

namespace App\Console\Commands;

use App\Services\Ocr\OcrDocumentService;
use Illuminate\Console\Command;

class RecoverStuckOcrDocumentsCommand extends Command
{
    protected $signature = 'ocr:recover-stuck {--dry-run : Report actions without changing records}';

    protected $description = 'Recover OCR documents stuck in queued/processing without an active worker job';

    public function handle(OcrDocumentService $ocrDocumentService): int
    {
        if ($this->option('dry-run')) {
            $queued = \App\Models\OcrDocument::query()
                ->where('status', \App\Models\OcrDocument::STATUS_QUEUED)
                ->where('updated_at', '<', now()->subMinutes((int) config('document-ai.queued_stuck_minutes', 5)))
                ->count();
            $processing = \App\Models\OcrDocument::query()
                ->whereIn('status', [
                    \App\Models\OcrDocument::STATUS_PROCESSING,
                    \App\Models\OcrDocument::STATUS_UPLOADING_TO_CLOUD,
                    \App\Models\OcrDocument::STATUS_FINALIZING,
                ])
                ->where(function ($query) {
                    $minutes = (int) config('document-ai.processing_stuck_minutes', 15);
                    $query->where('processing_started_at', '<', now()->subMinutes($minutes))
                        ->orWhere(function ($inner) use ($minutes) {
                            $inner->whereNull('processing_started_at')
                                ->where('updated_at', '<', now()->subMinutes($minutes));
                        });
                })
                ->count();

            $this->info("Dry run: {$queued} queued candidate(s), {$processing} processing candidate(s).");

            return self::SUCCESS;
        }

        $result = $ocrDocumentService->recoverStuckDocuments();

        $this->info(sprintf(
            'OCR recovery complete — redispatched: %d, timed out: %d, skipped: %d',
            $result['redispatched'],
            $result['timed_out'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
