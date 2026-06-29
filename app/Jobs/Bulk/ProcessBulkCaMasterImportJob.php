<?php

namespace App\Jobs\Bulk;

use App\Models\BulkAction;
use App\Services\Bulk\BulkCaMasterImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessBulkCaMasterImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $bulkActionId,
    ) {}

    public function handle(BulkCaMasterImportService $importService): void
    {
        $importService->processQueuedImport($this->bulkActionId);
    }

    public function failed(Throwable $exception): void
    {
        BulkAction::query()
            ->where('bulk_action_id', $this->bulkActionId)
            ->where('action_type', 'ca_master_import')
            ->update([
                'status' => 'Failed',
                'completed_at' => now(),
            ]);
    }
}
