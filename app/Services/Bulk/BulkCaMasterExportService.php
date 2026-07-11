<?php

namespace App\Services\Bulk;

use App\Models\BulkAction;
use App\Models\CaMaster;
use App\Services\Activity\ActivityLogService;
use App\Services\Leads\LeadActivityTimelineService;
use App\Services\Notifications\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BulkCaMasterExportService
{
    public function __construct(
        private readonly BulkExportFileWriter $fileWriter,
        private readonly ActivityLogService $activityLogService,
        private readonly BulkExportPermissionService $permissionService,
        private readonly NotificationService $notificationService,
        private readonly LeadActivityTimelineService $leadActivityTimelineService,
    ) {}

    public function availableColumns(): array
    {
        return BulkExportFileWriter::DEFAULT_COLUMNS;
    }

    public function preview(array $payload): array
    {
        $this->permissionService->authorize();

        $columns = $this->normalizeColumns($payload['columns'] ?? null);
        $query = $this->buildQuery($payload);
        $count = (clone $query)->count();

        return [
            'scope' => $payload['scope'],
            'format' => $payload['format'] ?? 'csv',
            'total_rows' => $count,
            'columns' => $columns,
            'uses_background' => $count > config('bulk.export_sync_row_limit', 200),
            'filters' => $payload['filters'] ?? [],
            'selected_count' => count($payload['ca_ids'] ?? []),
        ];
    }

    public function startExport(array $payload, ?string $performedBy = 'System'): array
    {
        $this->permissionService->authorize();

        $format = $payload['format'] ?? 'csv';
        $columns = $this->normalizeColumns($payload['columns'] ?? null);
        $query = $this->buildQuery($payload);
        $total = (clone $query)->count();

        if ($total === 0) {
            throw new RuntimeException('No records match the export criteria.');
        }

        $fileName = $this->buildFileName($format);
        $bulkAction = BulkAction::create([
            'action_type' => 'ca_master_export',
            'file_name' => $fileName,
            'export_format' => $format,
            'export_filters' => [
                'scope' => $payload['scope'],
                'filters' => $payload['filters'] ?? [],
                'ca_ids' => $payload['ca_ids'] ?? [],
                'columns' => $columns,
            ],
            'total_records' => $total,
            'processed_records' => 0,
            'success_records' => 0,
            'duplicate_records' => 0,
            'skipped_records' => 0,
            'failed_records' => 0,
            'imported_by' => $performedBy,
            'status' => 'Processing',
            'started_at' => now(),
        ]);

        $usesBackground = $total > config('bulk.export_sync_row_limit', 200);

        return [
            'bulk_action_id' => $bulkAction->bulk_action_id,
            'file_name' => $bulkAction->file_name,
            'total_rows' => $total,
            'format' => $format,
            'status' => $bulkAction->status,
            'uses_background' => $usesBackground,
            'download_ready' => ! $usesBackground,
        ];
    }

    public function processExport(int|string $bulkActionId): array
    {
        $bulkAction = BulkAction::query()
            ->where('action_type', 'ca_master_export')
            ->findOrFail($bulkActionId);

        if ($bulkAction->status === 'Completed') {
            return $this->statusPayload($bulkAction);
        }

        $filters = $bulkAction->export_filters ?? [];
        $payload = [
            'scope' => $filters['scope'] ?? 'all',
            'filters' => $filters['filters'] ?? [],
            'ca_ids' => $filters['ca_ids'] ?? [],
            'columns' => $filters['columns'] ?? array_keys(BulkExportFileWriter::DEFAULT_COLUMNS),
            'format' => $bulkAction->export_format ?? 'csv',
        ];

        $columns = $this->normalizeColumns($payload['columns']);
        $format = $payload['format'];
        $relativePath = $this->storagePath($bulkAction->bulk_action_id, $format);
        $absolutePath = Storage::disk('local')->path($relativePath);

        Storage::disk('local')->makeDirectory('bulk-exports/'.$bulkAction->bulk_action_id);

        $query = $this->buildQuery($payload);
        $chunkSize = config('bulk.export_chunk_size', 500);
        $exported = 0;

        if ($format === 'xlsx') {
            $rows = $this->mapChunkedRows($query, $chunkSize, function (int $processed) use ($bulkAction) {
                $bulkAction->update(['processed_records' => $processed]);
            });
            $exported = $this->fileWriter->writeXlsx($absolutePath, $columns, $rows);
        } else {
            $handle = fopen($absolutePath, 'w');
            if (! $handle) {
                throw new RuntimeException('Unable to create export file.');
            }
            fputcsv($handle, $this->fileWriter->headers($columns));

            $query->orderBy('ca_id')->chunkById($chunkSize, function ($records) use (&$exported, $handle, $columns, $bulkAction) {
                $activitySummaries = $this->leadActivityTimelineService->summariesForCaIds(
                    $records->pluck('ca_id')->map(fn ($id) => (int) $id)->all(),
                );
                foreach ($records as $record) {
                    $row = $this->mapRecord($record, $activitySummaries);
                    $line = [];
                    foreach ($columns as $column) {
                        $line[] = $row[$column] ?? '';
                    }
                    fputcsv($handle, $line);
                    $exported++;
                }
                $bulkAction->update(['processed_records' => $exported]);
            }, 'ca_id');

            fclose($handle);
        }

        $bulkAction->update([
            'processed_records' => $exported,
            'success_records' => $exported,
            'failed_records' => 0,
            'status' => 'Completed',
            'output_path' => $relativePath,
            'completed_at' => now(),
        ]);

        $this->activityLogService->log(
            'BULK_ACTIONS',
            'Bulk Export',
            (string) $bulkAction->bulk_action_id,
            sprintf(
                '%s — %d records exported (%s)',
                $bulkAction->file_name,
                $exported,
                strtoupper((string) $bulkAction->export_format),
            ),
            $bulkAction->imported_by ?? 'System',
        );

        $this->notificationService->exportCompleted(
            $bulkAction->file_name,
            $exported,
            (string) $bulkAction->export_format,
            $bulkAction->bulk_action_id,
            $bulkAction->imported_by,
        );

        return $this->statusPayload($bulkAction->fresh());
    }

    public function status(int|string $bulkActionId): array
    {
        $bulkAction = BulkAction::query()
            ->where('action_type', 'ca_master_export')
            ->findOrFail($bulkActionId);

        return $this->statusPayload($bulkAction);
    }

    public function downloadPath(int|string $bulkActionId): array
    {
        $bulkAction = BulkAction::query()
            ->where('action_type', 'ca_master_export')
            ->findOrFail($bulkActionId);

        if ($bulkAction->status !== 'Completed' || ! $bulkAction->output_path) {
            throw new RuntimeException('Export file is not ready for download.');
        }

        if (! Storage::disk('local')->exists($bulkAction->output_path)) {
            throw new RuntimeException('Export file was not found on disk.');
        }

        return [
            'path' => Storage::disk('local')->path($bulkAction->output_path),
            'file_name' => $bulkAction->file_name,
            'mime' => ($bulkAction->export_format ?? 'csv') === 'xlsx'
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                : 'text/csv; charset=UTF-8',
        ];
    }

    public function buildQuery(array $payload): Builder
    {
        $query = CaMaster::query()->with(['city', 'state', 'sourceLead', 'activeTeamAssignments.employee:employee_id,name']);

        return match ($payload['scope'] ?? 'all') {
            'selected' => $this->applySelectedScope($query, $payload['ca_ids'] ?? []),
            'filtered' => $this->applyFilters($query, $payload['filters'] ?? []),
            default => $query,
        };
    }

    private function applySelectedScope(Builder $query, array $caIds): Builder
    {
        $ids = array_values(array_filter($caIds, fn ($id) => $id !== null && $id !== ''));

        if ($ids === []) {
            throw new RuntimeException('Select at least one record to export.');
        }

        return $query->whereIn('ca_id', $ids);
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['state_id'])) {
            $query->where('state_id', (int) $filters['state_id']);
        }

        if (! empty($filters['city_id'])) {
            $query->where('city_id', (int) $filters['city_id']);
        }

        if (! empty($filters['source_id'])) {
            $query->where('source_id', (int) $filters['source_id']);
        }

        if (array_key_exists('is_newly_established', $filters) && $filters['is_newly_established'] !== null && $filters['is_newly_established'] !== '') {
            $query->where('is_newly_established', filter_var($filters['is_newly_established'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['search'])) {
            $term = '%'.strtolower((string) $filters['search']).'%';
            $query->where(function (Builder $builder) use ($term) {
                $builder
                    ->where(DB::raw('LOWER(firm_name)'), 'like', $term)
                    ->orWhere(DB::raw('LOWER(ca_name)'), 'like', $term)
                    ->orWhere(DB::raw('LOWER(mobile_no)'), 'like', $term)
                    ->orWhere(DB::raw('LOWER(alternate_mobile_no)'), 'like', $term)
                    ->orWhere(DB::raw('LOWER(email_id)'), 'like', $term)
                    ->orWhere(DB::raw('LOWER(gst_no)'), 'like', $term);
            });
        }

        return $query;
    }

    private function mapChunkedRows(Builder $query, int $chunkSize, callable $onProgress): \Generator
    {
        $processed = 0;
        $buffer = [];
        foreach ($query->orderBy('ca_id')->lazyById($chunkSize, 'ca_id') as $record) {
            $buffer[] = $record;
            if (count($buffer) >= $chunkSize) {
                $activitySummaries = $this->leadActivityTimelineService->summariesForCaIds(
                    collect($buffer)->pluck('ca_id')->map(fn ($id) => (int) $id)->all(),
                );
                foreach ($buffer as $bufferedRecord) {
                    yield $this->mapRecord($bufferedRecord, $activitySummaries);
                    $processed++;
                }
                $buffer = [];
                $onProgress($processed);
            }
        }
        if ($buffer !== []) {
            $activitySummaries = $this->leadActivityTimelineService->summariesForCaIds(
                collect($buffer)->pluck('ca_id')->map(fn ($id) => (int) $id)->all(),
            );
            foreach ($buffer as $bufferedRecord) {
                yield $this->mapRecord($bufferedRecord, $activitySummaries);
                $processed++;
            }
        }
        $onProgress($processed);
    }

    /**
     * @param  array<int, array<string, mixed>>  $activitySummaries
     */
    private function mapRecord(CaMaster $record, array $activitySummaries = []): array
    {
        $activity = $activitySummaries[(int) $record->ca_id] ?? null;

        return [
            'ca_id' => (string) $record->ca_id,
            'ca_name' => $record->ca_name,
            'firm_name' => $record->firm_name,
            'mobile_no' => $record->mobile_no,
            'alternate_mobile_no' => $record->alternate_mobile_no,
            'email_id' => $record->email_id,
            'gst_no' => $record->gst_no,
            'state' => $record->state?->state_name,
            'city' => $record->city?->city_name,
            'source' => $record->sourceLead?->source_name,
            'team_size' => ($record->team_size !== null && (int) $record->team_size > 0)
                ? (int) $record->team_size
                : 'Not Specified',
            'existing_software' => $record->existing_software,
            'website' => $record->website,
            'rating' => $record->rating,
            'status' => $record->status,
            'last_activity' => $activity
                ? trim(($activity['label'] ?? 'Activity').' · '.($activity['relative_label'] ?? '').' '.($activity['time_label'] ?? ''))
                : 'No Activity Yet',
            'is_newly_established' => $record->is_newly_established ? 'Yes' : 'No',
            'created_at' => $record->created_at?->toDateTimeString(),
            'updated_at' => $record->updated_at?->toDateTimeString(),
        ];
    }

    private function normalizeColumns(?array $columns): array
    {
        $available = array_keys(BulkExportFileWriter::DEFAULT_COLUMNS);
        $columns = $columns ? array_values(array_intersect($columns, $available)) : $available;

        if ($columns === []) {
            throw new RuntimeException('Select at least one export column.');
        }

        return $columns;
    }

    private function buildFileName(string $format): string
    {
        $stamp = now()->format('Ymd_His');

        return 'ca_master_export_'.$stamp.'.'.($format === 'xlsx' ? 'xlsx' : 'csv');
    }

    private function storagePath(int $bulkActionId, string $format): string
    {
        return 'bulk-exports/'.$bulkActionId.'/export.'.($format === 'xlsx' ? 'xlsx' : 'csv');
    }

    private function statusPayload(BulkAction $bulkAction): array
    {
        $total = max((int) $bulkAction->total_records, 1);
        $processed = (int) $bulkAction->processed_records;

        return [
            'bulk_action_id' => $bulkAction->bulk_action_id,
            'action_type' => $bulkAction->action_type,
            'file_name' => $bulkAction->file_name,
            'format' => $bulkAction->export_format,
            'total_rows' => $bulkAction->total_records,
            'processed_rows' => $processed,
            'exported_rows' => $bulkAction->success_records,
            'progress_percent' => min(100, (int) round(($processed / $total) * 100)),
            'status' => $bulkAction->status,
            'exported_by' => $bulkAction->imported_by ?? 'System',
            'download_ready' => $bulkAction->status === 'Completed' && (bool) $bulkAction->output_path,
            'started_at' => $bulkAction->started_at,
            'completed_at' => $bulkAction->completed_at,
            'created_at' => $bulkAction->created_at,
            'export_filters' => $bulkAction->export_filters,
        ];
    }
}
