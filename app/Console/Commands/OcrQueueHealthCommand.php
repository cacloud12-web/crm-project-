<?php

namespace App\Console\Commands;

use App\Jobs\ImportMasterCaOcrJob;
use App\Jobs\ParseOcrStructureJob;
use App\Jobs\ProcessOcrDocumentJob;
use App\Models\OcrDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OcrQueueHealthCommand extends Command
{
    protected $signature = 'ocr:queue-health';

    protected $description = 'Report OCR queue connection, pending/reserved/failed jobs, and stale documents';

    public function handle(): int
    {
        $connection = (string) config('queue.default');
        $jobQueue = (string) config('document-ai.queue', 'default');
        $workerList = (string) config('document-ai.queue_worker_list', 'ocr,default');

        $this->info('OCR queue health');
        $this->line('queue_connection='.$connection);
        $this->line('document_ai.queue='.$jobQueue);
        $this->line('worker_should_listen='.$workerList);
        $this->line('app_url='.(string) config('app.url'));
        $this->line('app_env='.(string) config('app.env'));
        $this->line('database='.(string) config('database.default').' / '.(string) config('database.connections.'.config('database.default').'.database'));
        $this->newLine();
        $this->line('OCR job classes → configured queue:');
        foreach ([
            ProcessOcrDocumentJob::class,
            ParseOcrStructureJob::class,
            ImportMasterCaOcrJob::class,
        ] as $class) {
            $this->line('  '.$class.' → '.$jobQueue);
        }

        if ($connection !== 'database' || ! Schema::hasTable('jobs')) {
            $this->warn('Pending job inspection requires QUEUE_CONNECTION=database with a jobs table.');
            $this->reportDocumentCounts();

            return self::SUCCESS;
        }

        $pending = DB::table('jobs')->whereNull('reserved_at')->count();
        $reserved = DB::table('jobs')->whereNotNull('reserved_at')->count();
        $failed = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0;
        $byQueue = DB::table('jobs')->select('queue', DB::raw('count(*) as c'))->groupBy('queue')->get();
        $oldest = DB::table('jobs')->orderBy('id')->first();
        $oldestAge = $oldest ? max(0, now()->getTimestamp() - (int) $oldest->available_at) : null;

        $this->newLine();
        $this->line('pending_jobs='.$pending);
        $this->line('reserved_jobs='.$reserved);
        $this->line('failed_jobs='.$failed);
        $this->line('oldest_pending_age_seconds='.($oldestAge === null ? 'n/a' : $oldestAge));
        foreach ($byQueue as $row) {
            $this->line('queue['.$row->queue.']='.$row->c);
        }
        $this->warn('worker_heartbeat=unknown (no false-positive active claim; run queue:work separately)');

        $this->reportDocumentCounts();

        return self::SUCCESS;
    }

    private function reportDocumentCounts(): void
    {
        $active = OcrDocument::query()->whereIn('status', OcrDocument::ACTIVE_STATUSES)->count();
        $queued = OcrDocument::query()->where('status', OcrDocument::STATUS_QUEUED)->count();
        $staleMinutes = (int) config('document-ai.queued_stuck_minutes', 5);
        $stale = OcrDocument::query()
            ->whereIn('status', OcrDocument::ACTIVE_STATUSES)
            ->where('updated_at', '<', now()->subMinutes($staleMinutes))
            ->count();

        $this->newLine();
        $this->line('active_ocr_documents='.$active);
        $this->line('queued_ocr_documents='.$queued);
        $this->line('stale_active_documents='.$stale.' (updated_at older than '.$staleMinutes.'m)');
    }
}
