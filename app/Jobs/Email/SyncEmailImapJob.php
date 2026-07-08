<?php

namespace App\Jobs\Email;

use App\Services\Email\EmailImapSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncEmailImapJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        public readonly string $mode = 'quick',
    ) {}

    public function uniqueId(): string
    {
        return 'email-imap-sync-'.$this->mode;
    }

    public function handle(EmailImapSyncService $imapSyncService): void
    {
        if ($this->mode === 'scheduled') {
            $imapSyncService->syncAllEnabledAccounts();

            return;
        }

        $imapSyncService->syncLatestInbox();
    }
}
