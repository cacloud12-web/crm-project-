<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Database\Seeder;

class CrmNotificationSeeder extends Seeder
{
    public function run(NotificationService $notificationService): void
    {
        $admin = User::query()->where('email', 'admin@ca.local')->first();

        if (! $admin) {
            return;
        }

        $samples = [
            [
                'type' => 'lead_assigned',
                'title' => 'New lead assigned',
                'message' => 'Sharma & Associates — Mumbai',
            ],
            [
                'type' => 'followup_due',
                'title' => 'Follow-up overdue',
                'message' => 'Patel Tax Consultants — Call scheduled today',
            ],
            [
                'type' => 'campaign_completed',
                'title' => 'WhatsApp campaign completed',
                'message' => 'Festival greeting — 248 recipients delivered',
            ],
            [
                'type' => 'import_completed',
                'title' => 'Bulk import completed',
                'message' => 'ca_master_import.csv — 120 rows inserted',
            ],
            [
                'type' => 'export_completed',
                'title' => 'Bulk export ready',
                'message' => 'ca_master_export.csv — 450 records exported',
            ],
            [
                'type' => 'new_employee',
                'title' => 'New employee added',
                'message' => 'Priya Sharma joined as Sales Executive',
            ],
            [
                'type' => 'activity_alert',
                'title' => 'Bulk status update',
                'message' => '42 leads moved to Pipeline by Admin User',
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
