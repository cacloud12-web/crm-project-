<?php

namespace App\Jobs\Email;

use App\Models\EmailLog;
use App\Services\Email\EmailLogService;
use App\Services\Email\GoDaddyMailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Queue job for GoDaddy email dispatch.
 * Mapping phase only: validates and stores the mapped mail object — does NOT send SMTP yet.
 */
class SendGoDaddyEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $emailLogId,
    ) {}

    public function handle(GoDaddyMailService $goDaddyMailService, EmailLogService $emailLogService): void
    {
        $log = EmailLog::query()->with('caMaster')->findOrFail($this->emailLogId);

        if (in_array($log->email_status, ['Delivered', 'Failed', 'Skipped'], true)) {
            return;
        }

        $emailLogService->markQueued($log);
    }

    public function failed(Throwable $exception): void
    {
        $log = EmailLog::query()->find($this->emailLogId);

        if (! $log) {
            return;
        }

        app(EmailLogService::class)->markFailed($log, $exception->getMessage());
    }
}
