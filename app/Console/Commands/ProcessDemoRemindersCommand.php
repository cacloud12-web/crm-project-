<?php

namespace App\Console\Commands;

use App\Services\Workflow\DemoReminderService;
use Illuminate\Console\Command;

class ProcessDemoRemindersCommand extends Command
{
    protected $signature = 'workflow:process-demo-reminders';

    protected $description = 'Send due demo reminders and retry failed ones';

    public function handle(DemoReminderService $demoReminderService): int
    {
        $sent = $demoReminderService->processDueReminders();
        $this->info("Processed {$sent} demo reminder(s).");

        return self::SUCCESS;
    }
}
