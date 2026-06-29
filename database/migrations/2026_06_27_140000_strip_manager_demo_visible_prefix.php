<?php

use App\Support\Demo\DemoDataCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->stripPrefixedColumn('employees', 'name');
        $this->stripPrefixedColumn('ca_masters', 'firm_name');
        $this->stripPrefixedColumn('users', 'name');

        if (Schema::hasTable('activity_logs')) {
            $this->stripPrefixedColumn('activity_logs', 'description');
            $this->stripPrefixedColumn('activity_logs', 'performed_by');
        }

        if (Schema::hasTable('follow_ups')) {
            $this->stripPrefixedColumn('follow_ups', 'remarks');
        }

        if (Schema::hasTable('whatsapp_campaigns')) {
            $this->renameCampaign('whatsapp_campaigns', 'Manager Demo — WhatsApp', DemoDataCatalog::DEMO_CAMPAIGN_NAMES['whatsapp']);
        }

        if (Schema::hasTable('email_campaigns')) {
            $this->renameCampaign('email_campaigns', 'Manager Demo — Email', DemoDataCatalog::DEMO_CAMPAIGN_NAMES['email']);
            DB::table('email_campaigns')
                ->where('subject', 'CA Cloud Desk — Manager Demo')
                ->update(['subject' => 'CA Cloud Desk Demo']);
        }

        if (Schema::hasTable('sms_campaigns')) {
            $this->renameCampaign('sms_campaigns', 'Manager Demo — SMS', DemoDataCatalog::DEMO_CAMPAIGN_NAMES['sms']);
        }
    }

    public function down(): void
    {
        // Non-destructive data cleanup; no rollback of renamed demo labels.
    }

    private function stripPrefixedColumn(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)
            ->where($column, 'like', 'Manager Demo%')
            ->orderBy($column)
            ->pluck($column)
            ->unique()
            ->each(function (string $value) use ($table, $column): void {
                $clean = DemoDataCatalog::stripVisiblePrefix($value);
                if ($clean !== $value) {
                    DB::table($table)->where($column, $value)->update([$column => $clean]);
                }
            });
    }

    private function renameCampaign(string $table, string $from, string $to): void
    {
        if (! DB::table($table)->where('campaign_name', $from)->exists()) {
            return;
        }

        if (DB::table($table)->where('campaign_name', $to)->exists()) {
            DB::table($table)->where('campaign_name', $from)->delete();

            return;
        }

        DB::table($table)->where('campaign_name', $from)->update(['campaign_name' => $to]);
    }
};
