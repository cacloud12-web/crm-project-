<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Database\Seeder;

/**
 * Local/QA sample notifications only — blocked in production.
 */
class CrmNotificationSeeder extends Seeder
{
    public function run(NotificationService $notificationService): void
    {
        if (app()->environment('production')) {
            if ($this->command) {
                $this->command->error('CrmNotificationSeeder is blocked in production.');
            }

            return;
        }

        $admin = User::query()
            ->whereIn('crm_role', ['admin', 'super_admin'])
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (! $admin) {
            return;
        }

        $samples = [
            [
                'type' => 'lead_assigned',
                'title' => 'New lead assigned',
                'message' => 'Demo Firm A — Sample City',
            ],
            [
                'type' => 'followup_due',
                'title' => 'Follow-up overdue',
                'message' => 'Demo Firm B — Call scheduled today',
            ],
            [
                'type' => 'campaign_completed',
                'title' => 'WhatsApp campaign completed',
                'message' => 'Festival greeting — sample recipients delivered',
            ],
            [
                'type' => 'import_completed',
                'title' => 'Bulk import completed',
                'message' => 'sample_import.csv — rows inserted',
            ],
            [
                'type' => 'export_completed',
                'title' => 'Bulk export ready',
                'message' => 'sample_export.csv — records exported',
            ],
            [
                'type' => 'new_employee',
                'title' => 'New employee added',
                'message' => 'A new employee joined as Sales Executive',
            ],
            [
                'type' => 'activity_alert',
                'title' => 'Bulk status update',
                'message' => 'Sample leads moved to Pipeline',
            ],
        ];

        foreach ($samples as $sample) {
            $notificationService->notifyUser(
                (int) $admin->id,
                $sample['type'],
                $sample['title'],
                $sample['message'],
            );
        }
    }
}
