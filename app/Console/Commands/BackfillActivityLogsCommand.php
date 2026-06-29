<?php

namespace App\Console\Commands;

use App\Services\Activity\ActivityLogService;
use Illuminate\Console\Command;

class BackfillActivityLogsCommand extends Command
{
    protected $signature = 'activity-logs:backfill';

    protected $description = 'Backfill activity_logs from existing CRM records';

    public function handle(ActivityLogService $activityLogService): int
    {
        $count = $activityLogService->backfillFromExistingData();

        $this->info("Activity logs ready: {$count} records.");

        return self::SUCCESS;
    }
}
