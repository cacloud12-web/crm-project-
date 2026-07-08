<?php

namespace App\Console\Commands;

use App\Jobs\Email\SyncEmailImapJob;
use App\Support\Queue\QueueDispatcher;
use Illuminate\Console\Command;

class SyncEmailCommand extends Command
{
    protected $signature = 'email:sync {--account= : Sync a single email account id}';

    protected $description = 'Sync IMAP inboxes for enabled email accounts';

    public function handle(): int
    {
        if ($this->option('account')) {
            return $this->call('email:sync-imap', [
                '--account' => $this->option('account'),
            ]);
        }

        QueueDispatcher::dispatchOrRun(new SyncEmailImapJob('scheduled'));
        $this->info('IMAP sync job dispatched.');

        return self::SUCCESS;
    }
}
