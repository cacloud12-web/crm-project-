<?php

namespace App\Jobs\Bulk;

use App\Models\BulkAction;
use App\Services\Bulk\BulkCaMasterExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessBulkCaMasterExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $bulkActionId,
    ) {}

    public function handle(BulkCaMasterExportService $exportService): void
    {
        $exportService->processExport($this->bulkActionId);
    }

    public function failed(Throwable $exception): void
    {
        BulkAction::query()
            ->where('bulk_action_id', $this->bulkActionId)
            ->where('action_type', 'ca_master_export')
            ->update([
                'status' => 'Failed',
                'completed_at' => now(),
            ]);
    }
}
