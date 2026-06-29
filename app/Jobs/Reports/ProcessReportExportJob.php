<?php

namespace App\Jobs\Reports;

use App\Services\Reports\ReportExportQueueService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessReportExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly string $exportId,
    ) {}

    public function handle(ReportExportQueueService $exportQueueService): void
    {
        $exportQueueService->process($this->exportId);
    }

    public function failed(Throwable $exception): void
    {
        app(ReportExportQueueService::class)->markFailed($this->exportId, $exception->getMessage());
    }
}
