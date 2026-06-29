<?php

namespace App\Services\Bulk;

use App\Models\BulkAction;
use Illuminate\Support\Collection;

class BulkExportHistoryService
{
    public function list(int $limit = 50): Collection
    {
        return BulkAction::query()
            ->where('action_type', 'ca_master_export')
            ->latest('bulk_action_id')
            ->limit($limit)
            ->get();
    }

    public function find(int|string $bulkActionId): BulkAction
    {
        return BulkAction::query()
            ->where('action_type', 'ca_master_export')
            ->findOrFail($bulkActionId);
    }

    public function detail(int|string $bulkActionId): array
    {
        $action = $this->find($bulkActionId);
        $total = max((int) $action->total_records, 1);

        return [
            'bulk_action_id' => $action->bulk_action_id,
            'action_type' => $action->action_type,
            'file_name' => $action->file_name,
            'format' => $action->export_format,
            'total_rows' => $action->total_records,
            'exported_rows' => $action->success_records,
            'processed_rows' => $action->processed_records,
            'progress_percent' => min(100, (int) round(((int) $action->processed_records / $total) * 100)),
            'exported_by' => $action->imported_by ?? 'System',
            'status' => $action->status,
            'download_ready' => $action->status === 'Completed' && (bool) $action->output_path,
            'started_at' => $action->started_at,
            'completed_at' => $action->completed_at,
            'created_at' => $action->created_at,
            'export_filters' => $action->export_filters,
        ];
    }
}
