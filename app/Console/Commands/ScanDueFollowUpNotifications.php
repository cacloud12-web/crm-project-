<?php

namespace App\Console\Commands;

use App\Models\FollowUp;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;

class ScanDueFollowUpNotifications extends Command
{
    protected $signature = 'notifications:scan-due-followups';

    protected $description = 'Create follow-up due notifications for pending items scheduled today or earlier';

    public function handle(NotificationService $notificationService): int
    {
        $today = now()->toDateString();
        $created = 0;

        FollowUp::query()
            ->with(['caMaster:ca_id,firm_name', 'employee:employee_id,name,email_id'])
            ->where('status', 'Pending')
            ->whereDate('scheduled_date', '<=', $today)
            ->orderBy('followup_id')
            ->chunkById(100, function ($followUps) use ($notificationService, $today, &$created) {
                foreach ($followUps as $followUp) {
                    $firm = $followUp->caMaster?->firm_name ?? 'Lead #'.$followUp->ca_id;
                    $title = 'Follow-up due';
                    $message = $firm.' — '.$followUp->followup_type;
                    $dedupKey = 'followup_due:'.$followUp->followup_id.':'.$today;
                    $extra = [
                        'entity_type' => 'follow_up',
                        'entity_id' => (string) $followUp->followup_id,
                        'dedup_key' => $dedupKey,
                        'payload' => [
                            'ca_id' => $followUp->ca_id,
                            'scheduled_date' => $followUp->scheduled_date,
                        ],
                    ];

                    $employeeEmail = $followUp->employee?->email_id;
                    $userId = $notificationService->resolveUserIdByEmployeeEmail($employeeEmail);

                    if ($userId) {
                        $result = $notificationService->notifyUser(
                            $userId,
                            'followup_due',
                            $title,
                            $message,
                            $extra,
                        );
                    } else {
                        $result = $notificationService->notifyManagement(
                            'followup_due',
                            $title,
                            $message,
                            $extra,
                        );
                    }

                    if ($result) {
                        $created++;
                    }
                }
            }, 'followup_id');

        $this->info("Created {$created} follow-up due notification(s).");

        return self::SUCCESS;
    }
}
