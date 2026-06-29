<?php

namespace App\Console\Commands;

use App\Services\FollowUp\FollowUpAutomationService;
use App\Services\FollowUp\FollowUpReminderService;
use App\Services\FollowUp\TaskService;
use Illuminate\Console\Command;

class ProcessFollowUpAutomationCommand extends Command
{
    protected $signature = 'followups:process-automation';

    protected $description = 'Mark overdue follow-ups/tasks and dispatch due reminders';

    public function handle(
        FollowUpAutomationService $automationService,
        FollowUpReminderService $reminderService,
        TaskService $taskService,
    ): int {
        $overdueFollowUps = $automationService->markOverdueFollowUps();
        $overdueTasks = $taskService->markOverdue();
        $reminders = $reminderService->processDueReminders();

        $this->info("Marked {$overdueFollowUps} follow-up(s) overdue, {$overdueTasks} task(s) overdue, sent {$reminders} reminder(s).");

        return self::SUCCESS;
    }
}
