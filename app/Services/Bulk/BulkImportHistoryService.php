<?php

namespace App\Services\Bulk;

use App\Models\BulkAction;
use App\Models\BulkActionLog;
use App\Models\ImportDuplicateLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BulkImportHistoryService
{
    public function list(int $limit = 50): Collection
    {
        return BulkAction::query()
            ->where('action_type', 'ca_master_import')
            ->latest('bulk_action_id')
            ->limit($limit)
            ->get();
    }

    public function find(int|string $bulkActionId): BulkAction
    {
        return BulkAction::query()
            ->where('action_type', 'ca_master_import')
            ->with(['logs' => fn ($query) => $query->orderBy('row_number')])
            ->findOrFail($bulkActionId);
    }

    /**
     * Permanently remove an import-history row (and its row logs).
     * Successfully imported CA Master leads are preserved (bulk_action_id nullOnDelete).
     */
    public function destroy(int|string $bulkActionId): void
    {
        DB::transaction(function () use ($bulkActionId): void {
            $action = BulkAction::query()
                ->where('action_type', 'ca_master_import')
                ->whereKey($bulkActionId)
                ->lockForUpdate()
                ->firstOrFail();

            BulkActionLog::query()
                ->where('bulk_action_id', $action->bulk_action_id)
                ->delete();

            ImportDuplicateLog::query()
                ->where('bulk_action_id', $action->bulk_action_id)
                ->delete();

            if ($action->output_path && Storage::disk('local')->exists($action->output_path)) {
                Storage::disk('local')->delete($action->output_path);
            }

            if (! $action->delete()) {
                throw new RuntimeException('Unable to delete import history record.');
            }
        });
    }

    public function detail(int|string $bulkActionId): array
    {
        $action = $this->find($bulkActionId);

        return [
            'bulk_action_id' => $action->bulk_action_id,
            'action_type' => $action->action_type,
            'file_name' => $action->file_name,
            'total_rows' => $action->total_records,
            'inserted_rows' => $action->success_records,
            'duplicate_rows' => $action->duplicate_records,
            'failed_rows' => $action->failed_records,
            'skipped_rows' => $action->skipped_records,
            'imported_by' => $action->imported_by ?? 'System',
            'status' => $action->status,
            'started_at' => $action->started_at,
            'completed_at' => $action->completed_at,
            'created_at' => $action->created_at,
            'error_row_count' => $action->logs
                ->whereIn('status', ['Failed', 'Duplicate'])
                ->count(),
        ];
    }

    public function errorRows(int|string $bulkActionId): array
    {
        $action = $this->find($bulkActionId);

        return $action->logs
            ->whereIn('status', ['Failed', 'Duplicate'])
            ->map(fn (BulkActionLog $log) => [
                'row_number' => $log->row_number,
                'original_data' => $log->original_data ?? [],
                'error_reason' => $log->error_message ?? '',
                'status' => $log->status,
            ])
            ->values()
            ->all();
    }

    public function failedRowsForReimport(int|string $bulkActionId): array
    {
        $action = $this->find($bulkActionId);

        $rows = $action->logs
            ->where('status', 'Failed')
            ->map(fn (BulkActionLog $log) => [
                'row_number' => $log->row_number,
                'original_data' => $log->original_data ?? [],
                'error_reason' => $log->error_message ?? '',
            ])
            ->values()
            ->all();

        if ($rows === []) {
            throw new RuntimeException('No failed rows available for re-import.');
        }

        return $rows;
    }
}
