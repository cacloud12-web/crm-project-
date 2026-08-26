<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillLastActivityFromAssignmentsCommand extends Command
{
    protected $signature = 'crm:backfill-last-activity-from-assignments
                            {--today : Only use assignment_histories from today}
                            {--dry-run : Show how many leads would be updated}';

    protected $description = 'Set ca_masters.last_activity_at from the latest assignment history when newer';

    public function handle(): int
    {
        if (! Schema::hasTable('ca_masters') || ! Schema::hasTable('assignment_histories')) {
            $this->error('Required tables missing.');

            return self::FAILURE;
        }

        if (! Schema::hasColumn('ca_masters', 'last_activity_at')) {
            $this->error('ca_masters.last_activity_at column missing.');

            return self::FAILURE;
        }

        $todayOnly = (bool) $this->option('today');
        $dryRun = (bool) $this->option('dry-run');

        $latestAssignments = DB::table('assignment_histories')
            ->select('ca_id', DB::raw('MAX(assigned_at) as latest_assigned_at'))
            ->when($todayOnly, function ($query) {
                $query->whereDate('assigned_at', now()->toDateString());
            })
            ->groupBy('ca_id');

        $candidates = DB::query()
            ->fromSub($latestAssignments, 'ah')
            ->join('ca_masters as cm', 'cm.ca_id', '=', 'ah.ca_id')
            ->whereNull('cm.deleted_at')
            ->where(function ($query) {
                $query->whereNull('cm.last_activity_at')
                    ->orWhereColumn('cm.last_activity_at', '<', 'ah.latest_assigned_at');
            })
            ->select('cm.ca_id', 'cm.last_activity_at', 'ah.latest_assigned_at');

        $count = (clone $candidates)->count();
        $this->info(($dryRun ? 'Would update' : 'Updating')." {$count} lead(s)".($todayOnly ? ' (today assignments only)' : '').'.');

        if ($dryRun || $count === 0) {
            return self::SUCCESS;
        }

        $updated = 0;
        $candidates->orderBy('cm.ca_id')->chunk(500, function ($rows) use (&$updated) {
            foreach ($rows as $row) {
                DB::table('ca_masters')
                    ->where('ca_id', $row->ca_id)
                    ->update(['last_activity_at' => $row->latest_assigned_at]);
                $updated++;
            }
        });

        $this->info("Updated {$updated} lead(s). Reload Lead Management to see Today.");

        return self::SUCCESS;
    }
}
