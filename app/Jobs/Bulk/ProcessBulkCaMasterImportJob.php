<?php

namespace App\Jobs\Bulk;

use App\Models\BulkAction;
use App\Services\Bulk\BulkCaMasterImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ProcessBulkCaMasterImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** One batch should finish inside shared-hosting drain windows. */
    public int $timeout = 280;

    public function __construct(
        public readonly int $bulkActionId,
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('bulk-ca-master-import:'.$this->bulkActionId))
                ->releaseAfter(15)
                ->expireAfter(300),
        ];
    }

    public function handle(BulkCaMasterImportService $importService): void
    {
        $importService->processQueuedImport($this->bulkActionId);
    }

    public function failed(Throwable $exception): void
    {
        $bulkAction = BulkAction::query()
            ->where('bulk_action_id', $this->bulkActionId)
            ->where('action_type', 'ca_master_import')
            ->where('status', 'Processing')
            ->first();

        if (! $bulkAction) {
            return;
        }

        // Preserve partial inserts — do not wipe a half-finished import to Failed.
        $bulkAction->update([
            'status' => ((int) $bulkAction->success_records > 0)
                ? 'Completed with errors'
                : 'Failed',
            'completed_at' => now(),
        ]);
    }
}
