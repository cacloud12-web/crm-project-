<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillLastActivityFromAssignmentsCommand extends Command
{
    protected $signature = 'crm:backfill-last-activity-from-assignments
                            {--today : Only consider activity from today}
                            {--calls : Also sync from call_logs (employee call work)}
                            {--dry-run : Show how many leads would be updated}';

    protected $description = 'Set ca_masters.last_activity_at from assignment history and optional call logs when newer';

    public function handle(): int
    {
        if (! Schema::hasTable('ca_masters')) {
            $this->error('ca_masters table missing.');

            return self::FAILURE;
        }

        if (! Schema::hasColumn('ca_masters', 'last_activity_at')) {
            $this->error('ca_masters.last_activity_at column missing.');

            return self::FAILURE;
        }

        $todayOnly = (bool) $this->option('today');
        $includeCalls = (bool) $this->option('calls');
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;

        if (Schema::hasTable('assignment_histories')) {
            $updated += $this->backfillFromAssignments($todayOnly, $dryRun);
        } else {
            $this->warn('assignment_histories missing — skipping assignment backfill.');
        }

        if ($includeCalls) {
            if (Schema::hasTable('call_logs')) {
                $updated += $this->backfillFromCalls($todayOnly, $dryRun);
            } else {
                $this->warn('call_logs missing — skipping call backfill.');
            }
        }

        if ($dryRun) {
            $this->info("Dry run complete. Would update {$updated} lead(s) total.");
        } else {
            $this->info("Updated {$updated} lead(s) total. Reload Lead Management to see Today.");
        }

        return self::SUCCESS;
    }

    private function backfillFromAssignments(bool $todayOnly, bool $dryRun): int
    {
        $latestAssignments = DB::table('assignment_histories')
            ->select('ca_id', DB::raw('MAX(assigned_at) as latest_at'))
            ->when($todayOnly, function ($query) {
                $query->whereDate('assigned_at', now()->toDateString());
            })
            ->groupBy('ca_id');

        return $this->applyLatestActivity($latestAssignments, 'assignment', $dryRun, $todayOnly);
    }

    private function backfillFromCalls(bool $todayOnly, bool $dryRun): int
    {
        $latestCalls = DB::table('call_logs')
            ->select('ca_id', DB::raw('MAX(called_at) as latest_at'))
            ->when($todayOnly, function ($query) {
                $query->whereDate('called_at', now()->toDateString());
            })
            ->groupBy('ca_id');

        return $this->applyLatestActivity($latestCalls, 'call', $dryRun, $todayOnly);
    }

    private function applyLatestActivity($latestSubquery, string $source, bool $dryRun, bool $todayOnly): int
    {
        $candidates = DB::query()
            ->fromSub($latestSubquery, 'src')
            ->join('ca_masters as cm', 'cm.ca_id', '=', 'src.ca_id')
            ->whereNull('cm.deleted_at')
            ->where(function ($query) {
                $query->whereNull('cm.last_activity_at')
                    ->orWhereColumn('cm.last_activity_at', '<', 'src.latest_at');
            })
            ->select('cm.ca_id', 'cm.last_activity_at', 'src.latest_at');

        $count = (clone $candidates)->count();
        $this->info(
            ($dryRun ? 'Would update' : 'Updating')
            ." {$count} lead(s) from {$source}"
            .($todayOnly ? ' (today only)' : '')
            .'.'
        );

        if ($dryRun || $count === 0) {
            return $count;
        }

        $updated = 0;
        $candidates->orderBy('cm.ca_id')->chunk(500, function ($rows) use (&$updated) {
            foreach ($rows as $row) {
                DB::table('ca_masters')
                    ->where('ca_id', $row->ca_id)
                    ->update(['last_activity_at' => $row->latest_at]);
                $updated++;
            }
        });

        return $updated;
    }
}
