<?php

namespace App\Console\Commands;

use App\Models\CaMaster;
use App\Services\Leads\CaMasterStatusSyncService;
use Illuminate\Console\Command;

class SyncCaMasterStatusesCommand extends Command
{
    protected $signature = 'crm:sync-master-statuses {--ca-id= : Sync a single CA master id}';

    protected $description = 'Backfill ca_masters.status from latest demo results and open demo activity';

    public function handle(CaMasterStatusSyncService $syncService): int
    {
        $caId = $this->option('ca-id');
        $updated = 0;
        $scanned = 0;

        $query = CaMaster::query()->orderBy('ca_id');
        if ($caId) {
            $query->where('ca_id', (int) $caId);
        }

        $query->chunkById(200, function ($leads) use ($syncService, &$updated, &$scanned) {
            foreach ($leads as $lead) {
                $scanned++;
                if ($syncService->syncFromLatestActivity($lead)) {
                    $updated++;
                }
            }
        });

        $this->info("Scanned {$scanned} records, updated {$updated}.");

        return self::SUCCESS;
    }
}
