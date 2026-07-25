<?php

namespace App\Services\SalesMapping;

use App\Models\MasterImportBatch;
use App\Models\SalesImportRow;
use App\Services\Mapping\SalesEmployeeListImportService;
use Illuminate\Support\Facades\Schema;

/**
 * Reconcile Sales Mapping batch counters from sales_import_rows (source of truth).
 */
class SalesBatchCounterService
{
    /**
     * @return array<string, int|null>
     */
    public function countsForBatch(int $batchId): array
    {
        $base = SalesImportRow::query()->where('import_batch_id', $batchId);
        $byStatus = (clone $base)
            ->selectRaw('mapping_status, COUNT(*) as total')
            ->groupBy('mapping_status')
            ->pluck('total', 'mapping_status');

        $duplicate = 0;
        if (Schema::hasColumn('sales_import_rows', 'duplicate_of_row_id')) {
            $duplicate = (int) (clone $base)->whereNotNull('duplicate_of_row_id')->count();
        }
        // Also count explicit duplicate status if present.
        $duplicate += (int) ($byStatus['duplicate'] ?? 0);

        return [
            'total_records' => (int) (clone $base)->count(),
            'matched_count' => (int) ($byStatus['matched'] ?? 0),
            'review_count' => (int) ($byStatus['needs_review'] ?? 0) + (int) ($byStatus['pending'] ?? 0),
            'unmatched_count' => (int) ($byStatus['unmatched'] ?? 0),
            'rejected_count' => (int) ($byStatus['rejected'] ?? 0),
            'skipped_count' => (int) ($byStatus['ignored'] ?? 0) + (int) ($byStatus['skipped'] ?? 0),
            'duplicate_count' => $duplicate,
            'failed_count' => (int) ($byStatus['failed'] ?? 0),
            'conflict_count' => (int) ($byStatus['unmatched'] ?? 0),
            'created_count' => (int) (clone $base)->count(),
        ];
    }

    /**
     * @return array{batch_id: int, before: array<string, mixed>, after: array<string, mixed>, changed: bool}|null
     */
    public function reconcileBatch(int $batchId, bool $apply = false): ?array
    {
        if (! Schema::hasTable('master_import_batches')) {
            return null;
        }

        $batch = MasterImportBatch::query()
            ->where('id', $batchId)
            ->where('source_type', SalesEmployeeListImportService::SOURCE_TYPE)
            ->first();

        if (! $batch) {
            return null;
        }

        $counts = $this->countsForBatch($batchId);
        $before = [
            'total_records' => (int) $batch->total_records,
            'matched_count' => (int) ($batch->matched_count ?? 0),
            'review_count' => (int) $batch->review_count,
            'unmatched_count' => (int) ($batch->unmatched_count ?? 0),
            'rejected_count' => (int) ($batch->rejected_count ?? 0),
            'skipped_count' => (int) ($batch->skipped_count ?? 0),
            'duplicate_count' => (int) $batch->duplicate_count,
            'failed_count' => (int) $batch->failed_count,
            'conflict_count' => (int) $batch->conflict_count,
        ];

        $changed = false;
        foreach ($counts as $key => $value) {
            if (! array_key_exists($key, $before)) {
                continue;
            }
            if ((int) $before[$key] !== (int) $value) {
                $changed = true;
                break;
            }
        }

        if ($apply && $changed) {
            $batch->forceFill($counts)->save();
        }

        return [
            'batch_id' => $batchId,
            'before' => $before,
            'after' => $counts,
            'changed' => $changed,
        ];
    }

    public function reconcileForRow(?int $batchId): void
    {
        if ($batchId === null || $batchId <= 0) {
            return;
        }
        $this->reconcileBatch($batchId, true);
    }
}
