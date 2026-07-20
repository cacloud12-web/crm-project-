<?php

namespace App\Console\Commands;

use App\Models\OcrDocument;
use App\Services\Ocr\OcrDocumentService;
use Illuminate\Console\Command;

class RecoverStuckOcrDocumentsCommand extends Command
{
    protected $signature = 'ocr:recover-stuck
        {--minutes=10 : Treat active OCR documents older than this many minutes as stuck}
        {--dry-run : Report actions without changing records}
        {--fail : Mark stuck processing docs failed instead of requeue (still releases reserved jobs)}';

    protected $description = 'Recover OCR documents stuck in queued/processing without an active worker job';

    public function handle(OcrDocumentService $ocrDocumentService): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $cutoff = now()->subMinutes($minutes);

        $queued = OcrDocument::query()
            ->where('status', OcrDocument::STATUS_QUEUED)
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->get(['id', 'status', 'processing_mode', 'processing_progress', 'updated_at']);

        $processing = OcrDocument::query()
            ->whereIn('status', [
                OcrDocument::STATUS_PROCESSING,
                OcrDocument::STATUS_UPLOADING_TO_CLOUD,
                OcrDocument::STATUS_FINALIZING,
            ])
            ->where(function ($query) use ($minutes) {
                $query->where('processing_started_at', '<', now()->subMinutes($minutes))
                    ->orWhere(function ($inner) use ($minutes) {
                        $inner->whereNull('processing_started_at')
                            ->where('updated_at', '<', now()->subMinutes($minutes));
                    });
            })
            ->orderBy('id')
            ->get(['id', 'status', 'processing_mode', 'processing_progress', 'updated_at']);

        $this->line('stale_queued='.$queued->count().' stale_processing='.$processing->count()
            .' (threshold='.$minutes.'m)');

        foreach ($queued->concat($processing) as $document) {
            $this->line(sprintf(
                '  id=%d status=%s mode=%s progress=%s',
                $document->id,
                $document->status,
                $document->processing_mode ?: 'n/a',
                mb_substr((string) $document->processing_progress, 0, 60),
            ));
        }

        if ($this->option('dry-run')) {
            $this->info(sprintf(
                'Dry run (%dm): %d queued candidate(s), %d processing candidate(s).',
                $minutes,
                $queued->count(),
                $processing->count(),
            ));

            return self::SUCCESS;
        }

        if ($this->option('fail')) {
            $failed = 0;
            foreach ($processing as $document) {
                $fresh = OcrDocument::query()->find($document->id);
                if ($fresh === null) {
                    continue;
                }
                $fresh->update([
                    'status' => OcrDocument::STATUS_FAILED,
                    'error_code' => 'processing_stuck',
                    'error_message' => 'OCR processing was stuck with no active queue worker. Use Retry.',
                    'processing_progress' => 'Failed — stuck processing recovered',
                ]);
                $failed++;
            }
            $this->info('marked_failed='.$failed.' (queued docs left for normal requeue)');
        }

        $result = $ocrDocumentService->recoverStuckDocuments($minutes);

        $this->info(sprintf(
            'OCR recovery complete — redispatched: %d, timed out: %d, skipped: %d, released_reserved: %d',
            $result['redispatched'],
            $result['timed_out'],
            $result['skipped'],
            $result['released_reserved'] ?? 0,
        ));

        return self::SUCCESS;
    }
}
