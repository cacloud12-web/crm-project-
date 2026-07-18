<?php

namespace App\Services\Demo;

use App\Support\Demo\DemoDataCatalog;
use Database\Seeders\ManagerDemoSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DemoDataCleanupService
{
    /**
     * @return array<string, int>
     */
    public function cleanup(bool $reseed = true): array
    {
        $counts = [];

        $demoCaIds = DB::table('ca_masters')
            ->whereIn('email_id', $this->demoLeadEmails())
            ->pluck('ca_id')
            ->all();

        $demoEmployeeIds = DB::table('employees')
            ->where(function ($q) {
                $q->where('email_id', 'like', 'manager.demo.exec%@example.local');
            })
            ->pluck('employee_id')
            ->all();

        $nonDemoCaIds = DB::table('ca_masters')
            ->when($demoCaIds !== [], fn ($q) => $q->whereNotIn('ca_id', $demoCaIds))
            ->pluck('ca_id')
            ->all();

        $knownDemoCampaigns = DemoDataCatalog::allKnownDemoCampaignNames();

        // Message logs for non-demo campaigns
        $nonDemoCampaignIds = [
            'whatsapp' => DB::table('whatsapp_campaigns')
                ->whereNotIn('campaign_name', $knownDemoCampaigns)
                ->pluck('id')->all(),
            'email' => DB::table('email_campaigns')
                ->whereNotIn('campaign_name', $knownDemoCampaigns)
                ->pluck('id')->all(),
            'sms' => DB::table('sms_campaigns')
                ->whereNotIn('campaign_name', $knownDemoCampaigns)
                ->pluck('id')->all(),
        ];

        if ($nonDemoCampaignIds['whatsapp'] !== []) {
            $counts['wa_message_logs'] = DB::table('wa_message_logs')
                ->whereIn('campaign_id', $nonDemoCampaignIds['whatsapp'])->delete();
        }
        if ($nonDemoCampaignIds['email'] !== []) {
            $counts['email_logs'] = DB::table('email_logs')
                ->whereIn('campaign_id', $nonDemoCampaignIds['email'])->delete();
        }
        if ($nonDemoCampaignIds['sms'] !== [] && Schema::hasTable('sms_logs')) {
            $counts['sms_logs'] = DB::table('sms_logs')
                ->whereIn('campaign_id', $nonDemoCampaignIds['sms'])->delete();
        }

        $counts['whatsapp_campaigns'] = DB::table('whatsapp_campaigns')
            ->whereNotIn('campaign_name', $knownDemoCampaigns)
            ->delete();
        $counts['email_campaigns'] = DB::table('email_campaigns')
            ->whereNotIn('campaign_name', $knownDemoCampaigns)
            ->delete();
        $counts['sms_campaigns'] = DB::table('sms_campaigns')
            ->whereNotIn('campaign_name', $knownDemoCampaigns)
            ->delete();

        $counts['bulk_action_logs'] = Schema::hasTable('bulk_action_logs')
            ? (int) DB::table('bulk_action_logs')->delete()
            : 0;
        $counts['bulk_actions'] = Schema::hasTable('bulk_actions')
            ? (int) DB::table('bulk_actions')->delete()
            : 0;

        if ($nonDemoCaIds !== []) {
            $counts['assignment_histories'] = DB::table('assignment_histories')
                ->whereIn('ca_id', $nonDemoCaIds)->delete();
            $counts['lead_assignment_engines'] = DB::table('lead_assignment_engines')
                ->whereIn('ca_id', $nonDemoCaIds)->delete();
            $counts['follow_ups'] = DB::table('follow_ups')
                ->whereIn('ca_id', $nonDemoCaIds)->delete();

            if (Schema::hasTable('wa_message_logs')) {
                DB::table('wa_message_logs')->whereIn('ca_id', $nonDemoCaIds)->delete();
            }
            if (Schema::hasTable('email_logs')) {
                DB::table('email_logs')->whereIn('ca_id', $nonDemoCaIds)->delete();
            }
            if (Schema::hasTable('sms_logs')) {
                DB::table('sms_logs')->whereIn('ca_id', $nonDemoCaIds)->delete();
            }
        }

        // QA / test leads (not demo catalog emails)
        $counts['ca_masters_qa'] = (int) DB::table('ca_masters')
            ->where(function ($q) {
                $q->where('firm_name', 'like', 'QA %')
                    ->orWhere('firm_name', 'like', 'FilterTest%')
                    ->orWhere('email_id', 'like', '%@test.local')
                    ->orWhere('email_id', 'like', 'qa%@test.local')
                    ->orWhere('email_id', 'like', 'qaretest%')
                    ->orWhere('email_id', 'like', 'qaemp%')
                    ->orWhere('email_id', 'like', 'filter%@test.local');
            })
            ->whereNotIn('email_id', $this->demoLeadEmails())
            ->delete();

        // Remaining non-demo leads
        $counts['ca_masters_other'] = (int) DB::table('ca_masters')
            ->whereNotIn('email_id', $this->demoLeadEmails())
            ->delete();

        // Non-demo employees (keep only manager.demo.exec*@example.local markers)
        $counts['employees'] = (int) DB::table('employees')
            ->where(function ($q) {
                $q->where('email_id', 'not like', 'manager.demo.exec%@example.local')
                    ->orWhereNull('email_id');
            })
            ->delete();

        // Orphan assignments / follow-ups (after lead cleanup)
        $orphanAssignmentIds = DB::table('lead_assignment_engines')
            ->whereNotIn('ca_id', DB::table('ca_masters')->select('ca_id'))
            ->orWhereNotIn('employee_id', DB::table('employees')->select('employee_id'))
            ->pluck('assignment_id');

        $counts['assignments_orphan'] = $orphanAssignmentIds->isEmpty()
            ? 0
            : (int) DB::table('lead_assignment_engines')->whereIn('assignment_id', $orphanAssignmentIds)->delete();

        $counts['follow_ups_orphan'] = (int) DB::table('follow_ups')
            ->whereNotIn('ca_id', DB::table('ca_masters')->select('ca_id'))
            ->delete();

        // Trim demo dataset to exactly 3 assignments / follow-ups (reseed fixes)
        if ($demoCaIds !== []) {
            DB::table('lead_assignment_engines')
                ->whereIn('ca_id', $demoCaIds)
                ->whereNotIn('ca_id', array_slice($demoCaIds, 0, 3))
                ->delete();
            DB::table('follow_ups')
                ->whereIn('ca_id', $demoCaIds)
                ->whereNotIn('ca_id', array_slice($demoCaIds, 0, 3))
                ->delete();
        }

        $counts['activity_logs'] = Schema::hasTable('activity_logs')
            ? (int) DB::table('activity_logs')->delete()
            : 0;

        if ($reseed) {
            Artisan::call('db:seed', [
                '--class' => ManagerDemoSeeder::class,
                '--force' => true,
            ]);
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    private function demoLeadEmails(): array
    {
        return array_map(
            fn (int $i) => DemoDataCatalog::DEMO_LEAD_EMAIL_PREFIX.$i.DemoDataCatalog::DEMO_LEAD_EMAIL_DOMAIN,
            range(1, 5),
        );
    }
}
