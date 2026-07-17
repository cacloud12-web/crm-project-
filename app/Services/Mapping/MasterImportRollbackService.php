<?php

namespace App\Services\Mapping;

use App\Models\CaMaster;
use App\Models\MasterImportBatch;
use App\Models\MasterMappingDecision;
use App\Services\Cache\CrmCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Reverses a completed mapping import batch:
 * - deletes auto-created masters that have no follow-up activity
 * - restores updated masters from pre-merge snapshots
 */
class MasterImportRollbackService
{
    public function __construct(
        private readonly CrmCacheService $cacheService,
    ) {}

    /**
     * @return array{rolled_back: bool, deleted: int, restored: int, skipped: int, message: string}
     */
    public function rollback(MasterImportBatch $batch, ?int $actorId = null): array
    {
        if (! $batch->isRollbackable()) {
            throw ValidationException::withMessages([
                'batch' => ['This import batch cannot be rolled back (already rolled back or empty).'],
            ]);
        }

        $deleted = 0;
        $restored = 0;
        $skipped = 0;

        DB::transaction(function () use ($batch, $actorId, &$deleted, &$restored, &$skipped) {
            foreach ($batch->created_ca_ids ?? [] as $caId) {
                $lead = CaMaster::query()->where('ca_id', (int) $caId)->first();
                if (! $lead) {
                    $skipped++;
                    continue;
                }
                if ($this->hasDownstreamActivity($lead)) {
                    $skipped++;
                    continue;
                }
                $lead->delete();
                $deleted++;
            }

            foreach ($batch->updated_snapshots ?? [] as $snapshot) {
                $caId = (int) ($snapshot['ca_id'] ?? 0);
                $before = is_array($snapshot['before'] ?? null) ? $snapshot['before'] : [];
                if ($caId < 1 || $before === []) {
                    $skipped++;
                    continue;
                }
                $lead = CaMaster::query()->where('ca_id', $caId)->lockForUpdate()->first();
                if (! $lead) {
                    $skipped++;
                    continue;
                }
                $lead->fill($before)->save();
                $restored++;
            }

            $batch->update([
                'status' => MasterImportBatch::STATUS_ROLLED_BACK,
                'rolled_back_at' => now(),
                'rolled_back_by' => $actorId,
                'progress_stage' => 'rolled_back',
                'remarks' => trim(($batch->remarks ? $batch->remarks.' | ' : '').'Rolled back'),
            ]);

            if (Schema::hasTable('master_mapping_decisions')) {
                MasterMappingDecision::query()
                    ->where('import_batch_id', $batch->id)
                    ->update(['remarks' => DB::raw("CONCAT(COALESCE(remarks, ''), ' [rolled_back]')")]);
            }
        });

        $this->cacheService->forgetMasterListings();
        $this->cacheService->forgetDashboardMetrics();

        return [
            'rolled_back' => true,
            'deleted' => $deleted,
            'restored' => $restored,
            'skipped' => $skipped,
            'message' => "Rollback complete: {$deleted} created records removed, {$restored} updates restored, {$skipped} skipped.",
        ];
    }

    private function hasDownstreamActivity(CaMaster $lead): bool
    {
        if (Schema::hasTable('follow_ups') && $lead->followUps()->exists()) {
            return true;
        }
        if (method_exists($lead, 'leadAssignments') && $lead->leadAssignments()->exists()) {
            return true;
        }

        return false;
    }
}
