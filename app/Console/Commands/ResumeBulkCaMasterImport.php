<?php

namespace App\Console\Commands;

use App\Jobs\Bulk\ProcessBulkCaMasterImportJob;
use App\Models\BulkAction;
use App\Services\Bulk\BulkCaMasterImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ResumeBulkCaMasterImport extends Command
{
    protected $signature = 'bulk:resume-ca-import
                            {id? : bulk_action_id to resume (defaults to latest Processing ca_master_import)}
                            {--sync : Process the next batch in this process instead of queueing}
                            {--all : Keep processing batches until the import finishes or the session expires}';

    protected $description = 'Resume a stuck CA Master bulk import that is still status=Processing';

    public function handle(BulkCaMasterImportService $importService): int
    {
        $id = $this->argument('id');
        $bulkAction = $id
            ? BulkAction::query()->where('bulk_action_id', (int) $id)->first()
            : BulkAction::query()
                ->where('action_type', 'ca_master_import')
                ->where('status', 'Processing')
                ->orderByDesc('bulk_action_id')
                ->first();

        if (! $bulkAction) {
            $this->error('No Processing ca_master_import bulk action found.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Resuming #%d — %s (%d / %d processed, %d inserted)',
            $bulkAction->bulk_action_id,
            $bulkAction->file_name,
            (int) $bulkAction->processed_records,
            (int) $bulkAction->total_records,
            (int) $bulkAction->success_records,
        ));

        $cacheKey = 'bulk_import_job:'.$bulkAction->bulk_action_id;
        if (! Cache::has($cacheKey)) {
            $diskPath = storage_path('app/bulk-import-jobs/'.$bulkAction->bulk_action_id.'.json');
            if (is_file($diskPath)) {
                $this->warn('Cache payload missing, but disk snapshot exists — attempting restore via processQueuedImport.');
            } else {
                $this->error('Session expired and no disk snapshot was found.');
                $this->line('Import #'.$bulkAction->bulk_action_id.' cannot be resumed in-place.');
                $this->line('Recovery: re-upload the SAME CSV in Bulk Import Wizard.');
                $this->line('Already-inserted leads ('.$bulkAction->success_records.') will be detected as duplicates and skipped.');
                $this->line('Remaining unprocessed rows will import normally.');
                $this->warn('Do not run cache:clear while an import is Processing.');

                return self::FAILURE;
            }
        }

        if (! $this->option('sync') && ! $this->option('all')) {
            ProcessBulkCaMasterImportJob::dispatch($bulkAction->bulk_action_id);
            $this->info('Dispatched ProcessBulkCaMasterImportJob. Run queue:work or wait for cron drain.');

            return self::SUCCESS;
        }

        $loops = 0;
        $maxLoops = $this->option('all') ? 500 : 1;

        do {
            $loops++;
            $result = $importService->processQueuedImport((int) $bulkAction->bulk_action_id, null, false);
            $fresh = $bulkAction->fresh();
            $this->line(sprintf(
                'Batch %d → status=%s processed=%d/%d inserted=%d',
                $loops,
                $fresh->status,
                (int) $fresh->processed_records,
                (int) $fresh->total_records,
                (int) $fresh->success_records,
            ));

            if (! ($result['continued'] ?? false)) {
                break;
            }
        } while ($loops < $maxLoops && $fresh->status === 'Processing');

        if (($result['continued'] ?? false) && $fresh->status === 'Processing') {
            $importService->dispatchImportContinuation((int) $bulkAction->bulk_action_id);
            $this->info('More rows remain — continuation job dispatched.');
        }

        $this->info('Done. Final status: '.($bulkAction->fresh()->status ?? 'unknown'));

        return self::SUCCESS;
    }
}
