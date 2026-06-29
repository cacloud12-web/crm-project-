<?php

namespace App\Services\Bulk;

use App\Http\Requests\Bulk\BulkStatusUpdateRequest;
use App\Models\BulkAction;
use App\Models\CaMaster;
use App\Services\Activity\ActivityLogService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class BulkStatusUpdateService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
    ) {}

    public function allowedStatuses(): array
    {
        return BulkStatusUpdateRequest::allowedStatuses();
    }

    public function execute(array $data, bool $preview = false): array
    {
        $caIds = array_values(array_unique(array_map('intval', $data['ca_ids'] ?? [])));
        $newStatus = trim((string) ($data['status'] ?? ''));

        if ($caIds === []) {
            throw new InvalidArgumentException('Select at least one record to update.');
        }

        if (! in_array($newStatus, $this->allowedStatuses(), true)) {
            throw new InvalidArgumentException('Invalid target status selected.');
        }

        $performedBy = $data['performed_by'] ?? 'System';
        $plan = $this->buildPlan($caIds, $newStatus);

        if ($preview) {
            return $this->summarize($plan, $newStatus, $performedBy, true);
        }

        try {
            return DB::transaction(function () use ($caIds, $newStatus, $performedBy) {
                $locked = CaMaster::query()
                    ->whereIn('ca_id', $caIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('ca_id');

                if ($locked->count() !== count($caIds)) {
                    throw new RuntimeException('One or more selected records are no longer available. No changes were saved.');
                }

                $updatedRows = [];
                foreach ($caIds as $caId) {
                    $lead = $locked->get($caId);
                    if (! $lead) {
                        throw new RuntimeException('Record #'.$caId.' could not be locked for update.');
                    }

                    $currentStatus = (string) ($lead->status ?? '');
                    if ($currentStatus === $newStatus) {
                        $updatedRows[] = [
                            'ca_id' => $caId,
                            'firm_name' => $lead->firm_name,
                            'ca_name' => $lead->ca_name,
                            'current_status' => $currentStatus,
                            'new_status' => $newStatus,
                            'result' => 'skipped',
                            'message' => 'Already at target status',
                        ];

                        continue;
                    }

                    $lead->status = $newStatus;
                    if (! $lead->save()) {
                        throw new RuntimeException('Failed to update status for '.$lead->firm_name.'.');
                    }

                    $this->activityLogService->log(
                        'CA_MASTER',
                        'Update Lead',
                        $this->shortId((string) $lead->ca_id),
                        ($lead->firm_name ?: $lead->ca_name).' — status '.$currentStatus.' → '.$newStatus,
                        $performedBy,
                    );

                    $updatedRows[] = [
                        'ca_id' => $caId,
                        'firm_name' => $lead->firm_name,
                        'ca_name' => $lead->ca_name,
                        'current_status' => $currentStatus,
                        'new_status' => $newStatus,
                        'result' => 'updated',
                        'message' => null,
                    ];
                }

                $summary = $this->summarize($updatedRows, $newStatus, $performedBy, false);
                $bulkAction = BulkAction::create([
                    'action_type' => 'ca_master_status_update',
                    'file_name' => 'Status → '.$newStatus,
                    'export_filters' => [
                        'target_status' => $newStatus,
                        'ca_ids' => $caIds,
                    ],
                    'total_records' => count($caIds),
                    'processed_records' => count($caIds),
                    'success_records' => $summary['updated_rows'],
                    'duplicate_records' => 0,
                    'skipped_records' => $summary['skipped_rows'],
                    'failed_records' => 0,
                    'imported_by' => $performedBy,
                    'status' => 'Completed',
                    'started_at' => now(),
                    'completed_at' => now(),
                ]);

                $this->activityLogService->log(
                    'BULK_ACTIONS',
                    'Bulk Status Update',
                    (string) $bulkAction->bulk_action_id,
                    sprintf(
                        '%d updated, %d skipped — target status %s',
                        $summary['updated_rows'],
                        $summary['skipped_rows'],
                        $newStatus,
                    ),
                    $performedBy,
                );

                $summary['bulk_action_id'] = $bulkAction->bulk_action_id;

                return $summary;
            });
        } catch (Throwable $e) {
            if ($e instanceof InvalidArgumentException || $e instanceof RuntimeException) {
                throw $e;
            }

            throw new RuntimeException('Bulk status update failed and was rolled back.', 0, $e);
        }
    }

    private function buildPlan(array $caIds, string $newStatus): array
    {
        $leads = CaMaster::query()
            ->whereIn('ca_id', $caIds)
            ->get()
            ->keyBy('ca_id');

        $missing = array_values(array_diff($caIds, $leads->keys()->all()));
        if ($missing !== []) {
            throw new InvalidArgumentException('Invalid or missing CA IDs: '.implode(', ', $missing));
        }

        $plan = [];
        foreach ($caIds as $caId) {
            $lead = $leads->get($caId);
            $currentStatus = (string) ($lead->status ?? '');
            $plan[] = [
                'ca_id' => $caId,
                'firm_name' => $lead->firm_name,
                'ca_name' => $lead->ca_name,
                'current_status' => $currentStatus,
                'new_status' => $newStatus,
                'result' => $currentStatus === $newStatus ? 'skipped' : 'ready',
                'message' => $currentStatus === $newStatus ? 'Already at target status' : null,
            ];
        }

        return $plan;
    }

    private function summarize(array $rows, string $newStatus, string $performedBy, bool $preview): array
    {
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $result = $row['result'] ?? 'ready';
            if ($result === 'skipped') {
                $skipped++;
            } elseif (in_array($result, ['updated', 'ready'], true)) {
                $updated++;
            }
        }

        return [
            'preview' => $preview,
            'target_status' => $newStatus,
            'total_rows' => count($rows),
            'updated_rows' => $updated,
            'skipped_rows' => $skipped,
            'failed_rows' => 0,
            'performed_by' => $performedBy,
            'rows' => $rows,
        ];
    }

    private function shortId(string $id): string
    {
        return strlen($id) > 8 ? substr($id, 0, 8).'…' : $id;
    }
}
