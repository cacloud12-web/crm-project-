<?php

namespace App\Console\Commands;

use App\Services\Workflow\LeadWorkflowService;
use Illuminate\Console\Command;

class SyncDemoFollowUpStateCommand extends Command
{
    protected $signature = 'crm:sync-demo-followups';

    protected $description = 'Repair completed demo follow-ups and sync demo_schedules for reports';

    public function handle(LeadWorkflowService $workflowService): int
    {
        $stats = $workflowService->syncAllDemoFollowUpState();

        $this->info('Demo follow-up sync complete.');
        $this->line('From existing demo results: '.$stats['from_demo_results']);
        $this->line('Orphaned Demo Scheduled → Demo Completed: '.$stats['orphaned_followups_retyped']);
        $this->line('Demo results created for reports: '.$stats['demo_results_created']);

        return self::SUCCESS;
    }
}
