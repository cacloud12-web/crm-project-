<?php

namespace Database\Seeders;

use App\Models\CaMaster;
use App\Models\City;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\LeadAssignmentEngine;
use App\Models\SourceLead;
use App\Models\State;
use App\Models\User;
use App\Services\Email\EmailCampaignService;
use App\Services\Sms\SmsCampaignService;
use App\Services\WhatsApp\WhatsAppCampaignService;
use App\Support\Demo\DemoDataCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent manager-demo dataset (identified by demo emails).
 * Run: php artisan db:seed --class=ManagerDemoSeeder --force
 */
class ManagerDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->trimDuplicateDemoCampaigns();
        $this->normalizeLegacyDemoLabels();

        $stateId = State::query()->value('state_id') ?? State::query()->create(['state_name' => 'Maharashtra'])->state_id;
        $cityId = City::query()->where('state_id', $stateId)->value('city_id')
            ?? City::query()->create(['city_name' => 'Mumbai', 'state_id' => $stateId])->city_id;
        $sourceId = SourceLead::query()->value('source_id')
            ?? SourceLead::query()->create(['source_name' => 'Website'])->source_id;

        $leads = [];
        $leadDefs = [
            ['firm' => 'Sharma & Co', 'ca' => 'R. Sharma', 'status' => 'Hot', 'rating' => 5],
            ['firm' => 'Jain Associates', 'ca' => 'P. Jain', 'status' => 'Pipeline', 'rating' => 4],
            ['firm' => 'Iyer Partners', 'ca' => 'K. Iyer', 'status' => 'Demo Scheduled', 'rating' => 5],
            ['firm' => 'Patel Tax', 'ca' => 'A. Patel', 'status' => 'Warm', 'rating' => 3],
            ['firm' => 'Bose Consultants', 'ca' => 'S. Bose', 'status' => 'New', 'rating' => 4],
        ];

        foreach ($leadDefs as $i => $def) {
            $email = 'manager.demo.lead'.($i + 1).'@ca.local';
            $leads[] = CaMaster::query()->updateOrCreate(
                ['email_id' => $email],
                [
                    'firm_name' => $def['firm'],
                    'ca_name' => $def['ca'],
                    'mobile_no' => '987650000'.($i + 1),
                    'city_id' => $cityId,
                    'state_id' => $stateId,
                    'source_id' => $sourceId,
                    'team_size' => 5 + $i,
                    'rating' => $def['rating'],
                    'status' => $def['status'],
                    'is_newly_established' => $i < 2,
                ],
            );
        }

        $employees = [];
        $empDefs = [
            ['name' => 'Priya Sharma', 'email' => 'employee@ca.local', 'mobile' => '9000000001'],
            ['name' => 'Anita Desai', 'email' => 'manager.demo.exec2@ca.local', 'mobile' => '9000000002'],
            ['name' => 'Vikram Singh', 'email' => 'manager.demo.exec3@ca.local', 'mobile' => '9000000003'],
        ];

        foreach ($empDefs as $def) {
            $employees[] = Employee::query()->updateOrCreate(
                ['email_id' => $def['email']],
                [
                    'name' => $def['name'],
                    'mobile_no' => $def['mobile'],
                    'role' => 'Sales Executive',
                    'status' => 'Active',
                    'city_id' => $cityId,
                ],
            );
        }

        User::query()->where('email', 'employee@ca.local')->update(['name' => 'Priya Sharma']);

        for ($i = 0; $i < 3; $i++) {
            LeadAssignmentEngine::query()->updateOrCreate(
                [
                    'ca_id' => $leads[$i]->ca_id,
                    'employee_id' => $employees[$i]->employee_id,
                    'status' => 'Active',
                ],
                [
                    'assigned_date' => now()->toDateString(),
                    'assignment_type' => 'Manual',
                    'rotation_logic_used' => 'MANAGER_DEMO',
                    'priority_score' => 1,
                    'target_leads' => 0,
                    'achieved_leads' => 0,
                ],
            );

            FollowUp::query()->updateOrCreate(
                [
                    'ca_id' => $leads[$i]->ca_id,
                    'employee_id' => $employees[$i]->employee_id,
                    'scheduled_date' => now()->toDateString(),
                ],
                [
                    'followup_type' => 'Call',
                    'status' => 'Scheduled',
                    'remarks' => 'Demo follow-up #'.($i + 1),
                ],
            );
        }

        $waService = app(WhatsAppCampaignService::class);
        $emailService = app(EmailCampaignService::class);
        $smsService = app(SmsCampaignService::class);

        $admin = User::query()->where('email', 'admin@ca.local')->first();
        if ($admin) {
            Auth::login($admin);
        }

        $this->ensureDemoCampaign(
            'whatsapp_campaigns',
            DemoDataCatalog::DEMO_CAMPAIGN_NAMES['whatsapp'],
            fn () => $waService->create([
                'campaign_name' => DemoDataCatalog::DEMO_CAMPAIGN_NAMES['whatsapp'],
                'campaign_type' => 'Demo Confirmation',
                'audience_mode' => 'all_leads',
                'message_template' => 'Hello {{name}}, your CA Cloud Desk demo is confirmed. (Simulation)',
            ]),
        );

        $this->ensureDemoCampaign(
            'email_campaigns',
            DemoDataCatalog::DEMO_CAMPAIGN_NAMES['email'],
            fn () => $emailService->create([
                'campaign_name' => DemoDataCatalog::DEMO_CAMPAIGN_NAMES['email'],
                'campaign_type' => 'Bulk Email',
                'audience_mode' => 'all_leads',
                'subject' => 'CA Cloud Desk Demo',
                'body_template' => '<p>Thank you for your interest in CA Cloud Desk. (Simulation)</p>',
            ]),
        );

        $this->ensureDemoCampaign(
            'sms_campaigns',
            DemoDataCatalog::DEMO_CAMPAIGN_NAMES['sms'],
            fn () => $smsService->create([
                'campaign_name' => DemoDataCatalog::DEMO_CAMPAIGN_NAMES['sms'],
                'campaign_type' => 'Demo Reminder',
                'audience_mode' => 'all_leads',
                'sender_id' => 'CACLDSK',
                'message_template' => 'Reminder: Your CA Cloud Desk demo is tomorrow. (Simulation)',
            ]),
        );

        $this->command?->info('Manager demo data ready: 5 leads, 3 employees, 3 assignments, 3 follow-ups, 3 campaigns.');
    }

    private function trimDuplicateDemoCampaigns(): void
    {
        foreach (['whatsapp_campaigns', 'email_campaigns', 'sms_campaigns'] as $table) {
            $keepIds = DB::table($table)
                ->whereIn('campaign_name', DemoDataCatalog::allKnownDemoCampaignNames())
                ->orderBy('id')
                ->pluck('id');

            if ($keepIds->count() > 1) {
                DB::table($table)->whereIn('id', $keepIds->slice(1)->all())->delete();
            }
        }
    }

    private function normalizeLegacyDemoLabels(): void
    {
        foreach (DemoDataCatalog::LEGACY_DEMO_CAMPAIGN_NAMES as $legacyName) {
            $clean = DemoDataCatalog::stripVisiblePrefix($legacyName);
            foreach (['whatsapp_campaigns', 'email_campaigns', 'sms_campaigns'] as $table) {
                if (! DB::table($table)->where('campaign_name', $legacyName)->exists()) {
                    continue;
                }
                if (DB::table($table)->where('campaign_name', $clean)->exists()) {
                    DB::table($table)->where('campaign_name', $legacyName)->delete();
                } else {
                    DB::table($table)->where('campaign_name', $legacyName)->update(['campaign_name' => $clean]);
                }
            }
        }

        Employee::query()
            ->whereIn('email_id', DemoDataCatalog::DEMO_EMPLOYEE_EMAILS)
            ->get(['employee_id', 'name'])
            ->each(function (Employee $employee): void {
                $clean = DemoDataCatalog::stripVisiblePrefix($employee->name);
                if ($clean !== $employee->name) {
                    $employee->update(['name' => $clean]);
                }
            });

        CaMaster::query()
            ->where('email_id', 'like', DemoDataCatalog::DEMO_LEAD_EMAIL_PREFIX.'%')
            ->get(['ca_id', 'firm_name'])
            ->each(function (CaMaster $lead): void {
                $clean = DemoDataCatalog::stripVisiblePrefix($lead->firm_name);
                if ($clean !== $lead->firm_name) {
                    $lead->update(['firm_name' => $clean]);
                }
            });
    }

    private function ensureDemoCampaign(string $table, string $name, callable $create): void
    {
        if (DB::table($table)->where('campaign_name', $name)->exists()) {
            return;
        }

        $create();
    }
}
