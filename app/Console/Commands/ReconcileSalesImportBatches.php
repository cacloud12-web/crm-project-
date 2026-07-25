<?php

namespace App\Console\Commands;

use App\Models\MasterImportBatch;
use App\Services\Mapping\SalesEmployeeListImportService;
use App\Services\SalesMapping\SalesBatchCounterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ReconcileSalesImportBatches extends Command
{
    protected $signature = 'sales-list:reconcile-batches
                            {--batch= : Reconcile one import_batch_id}
                            {--all : Reconcile every employee_sales_list batch}
                            {--apply : Persist counter updates (default is dry-run)}';

    protected $description = 'Reconcile Sales Mapping batch counters from sales_import_rows (dry-run by default)';

    public function __construct(
        private readonly SalesBatchCounterService $counters,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Schema::hasTable('master_import_batches') || ! Schema::hasTable('sales_import_rows')) {
            $this->error('Required tables missing.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $batchId = $this->option('batch') !== null && $this->option('batch') !== ''
            ? (int) $this->option('batch')
            : null;
        $all = (bool) $this->option('all');

        if (! $all && $batchId === null) {
            $this->error('Specify --all or --batch=');

            return self::FAILURE;
        }

        $this->info($apply ? 'APPLY mode — counters will be updated.' : 'DRY-RUN — no counter writes.');

        $query = MasterImportBatch::query()
            ->where('source_type', SalesEmployeeListImportService::SOURCE_TYPE)
            ->orderBy('id');
        if ($batchId !== null) {
            $query->where('id', $batchId);
        }

        $changed = 0;
        $checked = 0;
        $query->chunkById(100, function ($batches) use ($apply, &$changed, &$checked) {
            foreach ($batches as $batch) {
                $checked++;
                $result = $this->counters->reconcileBatch((int) $batch->id, $apply);
                if (! $result) {
                    continue;
                }
                if ($result['changed']) {
                    $changed++;
                    $this->line(sprintf(
                        'Batch #%d %s — matched %d→%d review %d→%d unmatched %d→%d rejected %d→%d',
                        $result['batch_id'],
                        $apply ? 'UPDATED' : 'WOULD UPDATE',
                        $result['before']['matched_count'],
                        $result['after']['matched_count'],
                        $result['before']['review_count'],
                        $result['after']['review_count'],
                        $result['before']['unmatched_count'],
                        $result['after']['unmatched_count'],
                        $result['before']['rejected_count'],
                        $result['after']['rejected_count'],
                    ));
                }
            }
        });

        $this->info("Checked {$checked} batch(es); ".($apply ? 'updated' : 'would update')." {$changed}.");

        return self::SUCCESS;
    }
}
