<?php

namespace App\Console\Commands;

use App\Services\Email\EmailImapSyncService;
use Illuminate\Console\Command;

class SyncEmailImapCommand extends Command
{
    protected $signature = 'email:sync-imap {--account= : Sync a single email account id}';

    protected $description = 'Sync IMAP inboxes for enabled enterprise email accounts (alias: email:sync)';

    public function handle(EmailImapSyncService $syncService): int
    {
        $accountId = $this->option('account');

        if ($accountId) {
            $account = \App\Models\EmailSetting::query()->findOrFail($accountId);
            $result = $syncService->syncAccount($account);
            $this->info($result['message']);

            return self::SUCCESS;
        }

        $results = $syncService->syncAllEnabledAccounts();
        if ($results->isEmpty()) {
            $this->warn('No active email accounts are eligible for IMAP sync. Enable IMAP on the account or configure Gmail SMTP with an app password.');

            return self::SUCCESS;
        }

        foreach ($results as $result) {
            $line = ($result['from_email'] ?? 'account').': '.$result['message'];
            if (isset($result['fetched'])) {
                $line .= ' (fetched: '.$result['fetched']
                    .', stored: '.($result['synced'] ?? 0)
                    .', duplicates: '.($result['duplicates_skipped'] ?? 0).')';
            }
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
