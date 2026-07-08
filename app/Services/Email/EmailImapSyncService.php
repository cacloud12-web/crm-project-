<?php

namespace App\Services\Email;

use App\Models\CaMaster;
use App\Models\EmailAttachment;
use App\Models\EmailInboundMessage;
use App\Models\EmailLog;
use App\Models\EmailSetting;
use App\Models\EmailSyncLog;
use App\Models\Employee;
use App\Services\Activity\ActivityLogService;
use App\Services\Leads\LeadFieldNormalizationService;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class EmailImapSyncService
{
    public function __construct(
        private readonly EmailImapConnectionService $imapConnection,
        private readonly EmailThreadService $threadService,
        private readonly ActivityLogService $activityLogService,
        private readonly LeadFieldNormalizationService $fieldNormalization,
        private readonly NotificationService $notificationService,
    ) {}

    public function syncAccount(EmailSetting $account, int $limit = 50, bool $inboxOnly = false, bool $quick = false): array
    {
        if (! $account->isImapConfigured()) {
            return $this->syncResult(0, 0, 0, 0, 0, 'IMAP is not enabled for this account.', success: false);
        }

        if ($quick) {
            $limit = min($limit, (int) config('crm_email.imap_sync_quick_limit', 25));
            $inboxOnly = true;
        }

        $config = $this->imapConfigFromSetting($account);

        Log::info('IMAP sync starting', [
            'account_id' => $account->id,
            'from_email' => $account->from_email,
            'quick' => $quick,
            'limit' => $limit,
        ]);

        $syncLog = EmailSyncLog::query()->create([
            'email_setting_id' => $account->id,
            'status' => EmailSyncLog::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        $synced = 0;
        $matched = 0;
        $fetched = 0;
        $duplicatesSkipped = 0;
        $syncState = is_array($account->imap_sync_state) ? $account->imap_sync_state : [];
        $sinceDate = $quick ? null : $this->resolveSinceDate($account);

        try {
            Log::info('IMAP connected', [
                'account_id' => $account->id,
                'host' => $config['imap_host'] ?? null,
            ]);

            $synced += $this->processFolderMessages(
                $account,
                $config,
                'INBOX',
                EmailInboundMessage::DIRECTION_INBOUND,
                $limit,
                $sinceDate,
                $fetched,
                $matched,
                $duplicatesSkipped,
                $syncState,
                $quick,
            );

            if (! $inboxOnly) {
                foreach ($this->imapConnection->sentFolderCandidates() as $sentFolder) {
                    $before = $synced;
                    $synced += $this->processFolderMessages(
                        $account,
                        $config,
                        $sentFolder,
                        EmailInboundMessage::DIRECTION_SENT,
                        min($limit, 25),
                        $sinceDate,
                        $fetched,
                        $matched,
                        $duplicatesSkipped,
                        $syncState,
                        false,
                    );
                    if ($synced > $before) {
                        break;
                    }
                }
            }

            $reconciled = $this->reconcileInboundReplies($account);
            $matched += $reconciled;

            $account->update([
                'last_imap_sync_at' => now(),
                'imap_sync_state' => $syncState,
            ]);

            $syncLog->update([
                'status' => EmailSyncLog::STATUS_SUCCESS,
                'messages_fetched' => $fetched,
                'messages_stored' => $synced,
                'leads_matched' => $matched,
                'finished_at' => now(),
                'details' => [
                    'duplicates_skipped' => $duplicatesSkipped,
                    'since_date' => $sinceDate?->toIso8601String(),
                    'inbox_only' => $inboxOnly,
                    'quick' => $quick,
                    'reconciled' => $reconciled,
                ],
            ]);

            Log::info('IMAP sync completed', [
                'account_id' => $account->id,
                'fetched' => $fetched,
                'inserted' => $synced,
                'duplicates_skipped' => $duplicatesSkipped,
                'matched' => $matched,
            ]);

            return $this->syncResult(
                $synced,
                $matched,
                $fetched,
                $duplicatesSkipped,
                $reconciled,
                $synced > 0
                    ? 'Latest emails synced successfully.'
                    : 'No new replies found.',
                $account,
                $syncLog,
            );
        } catch (Throwable $exception) {
            Log::error('IMAP sync failed', [
                'account_id' => $account->id,
                'error' => $exception->getMessage(),
                'fetched' => $fetched,
                'inserted' => $synced,
                'duplicates_skipped' => $duplicatesSkipped,
            ]);
            $syncLog->update([
                'status' => EmailSyncLog::STATUS_FAILED,
                'messages_fetched' => $fetched,
                'messages_stored' => $synced,
                'leads_matched' => $matched,
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
                'details' => [
                    'duplicates_skipped' => $duplicatesSkipped,
                    'since_date' => $sinceDate?->toIso8601String(),
                ],
            ]);

            return $this->syncResult(
                $synced,
                $matched,
                $fetched,
                $duplicatesSkipped,
                0,
                'IMAP sync failed: '.$exception->getMessage(),
                $account,
                $syncLog,
                false,
            );
        }
    }

    /**
     * Fast synchronous inbox sync for HTTP / UI (no queue worker required).
     *
     * @return array<string, mixed>
     */
    public function syncLatestInbox(): array
    {
        $quickLimit = (int) config('crm_email.imap_sync_quick_limit', 25);

        $accounts = EmailSetting::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->filter(fn (EmailSetting $account) => $account->isImapConfigured());

        if ($accounts->isEmpty()) {
            return [
                'success' => false,
                'message' => 'No active email account is configured for IMAP sync.',
                'accounts' => [],
                'synced' => 0,
                'fetched' => 0,
                'duplicates_skipped' => 0,
            ];
        }

        $results = $accounts->map(fn (EmailSetting $account) => array_merge(
            ['account_id' => $account->id, 'from_email' => $account->from_email],
            $this->syncAccount($account, $quickLimit, true, true),
        ))->values()->all();

        $totalSynced = (int) collect($results)->sum('synced');
        $totalFetched = (int) collect($results)->sum('fetched');
        $totalDuplicates = (int) collect($results)->sum('duplicates_skipped');

        return [
            'success' => collect($results)->every(fn (array $row) => (bool) ($row['success'] ?? true)),
            'message' => $totalSynced > 0 ? 'Latest emails synced successfully.' : 'No new replies found.',
            'synced' => $totalSynced,
            'fetched' => $totalFetched,
            'duplicates_skipped' => $totalDuplicates,
            'accounts' => $results,
        ];
    }

    public function syncAllEnabledAccounts(): Collection
    {
        $limit = (int) config('crm_email.imap_sync_scheduled_limit', 50);

        return EmailSetting::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (EmailSetting $account) => $account->isImapConfigured())
            ->values()
            ->map(fn (EmailSetting $account) => array_merge(
                ['account_id' => $account->id, 'from_email' => $account->from_email],
                $this->syncAccount($account, $limit, false, false),
            ));
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $syncState
     */
    private function processFolderMessages(
        EmailSetting $account,
        array $config,
        string $folder,
        string $direction,
        int $limit,
        ?\DateTimeInterface $sinceDate,
        int &$fetched,
        int &$matched,
        int &$duplicatesSkipped,
        array &$syncState,
        bool $quick = false,
    ): int {
        $stored = 0;
        $sinceUid = (int) ($syncState[$folder]['last_uid'] ?? 0);
        $messages = $this->imapConnection->fetchMessages(
            $config,
            $folder,
            $limit,
            $sinceUid,
            $sinceDate,
            false,
            $quick,
        );
        $fetched += count($messages);
        $maxUid = $sinceUid;

        foreach ($messages as $message) {
            if (empty($message['message_id'])) {
                continue;
            }

            $uid = (int) ($message['uid'] ?? 0);
            if ($uid > $maxUid) {
                $maxUid = $uid;
            }

            if ($this->isDuplicateMessage($account, $message, $folder)) {
                $duplicatesSkipped++;

                continue;
            }

            $inbound = EmailInboundMessage::query()->create([
                'email_setting_id' => $account->id,
                'folder' => $folder,
                'imap_uid' => $uid ?: null,
                'direction' => $direction,
                'message_id' => $message['message_id'],
                'in_reply_to' => $message['in_reply_to'] ?? null,
                'references_header' => $message['references'] ?? null,
                'from_email' => $message['from_email'] ?? '',
                'to_email' => $message['to_email'] ?? null,
                'subject' => $message['subject'] ?? null,
                'body_text' => $message['body_text'] ?? null,
                'body_html' => $message['body_html'] ?? null,
                'received_at' => $message['received_at'] ?? now(),
                'is_read' => (bool) ($message['is_seen'] ?? false),
                'match_status' => 'unmatched',
                'raw_headers' => $message['raw_headers'] ?? null,
            ]);

            foreach ($message['attachments'] ?? [] as $attachment) {
                $storedPath = null;
                if (! empty($attachment['content'])) {
                    $storedPath = 'email-attachments/'.$inbound->id.'/'.($attachment['filename'] ?? 'attachment');
                    Storage::disk('local')->put($storedPath, $attachment['content']);
                }

                EmailAttachment::query()->create([
                    'email_inbound_message_id' => $inbound->id,
                    'filename' => $attachment['filename'] ?? 'attachment',
                    'mime_type' => $attachment['mime_type'] ?? null,
                    'size_bytes' => $attachment['size_bytes'] ?? null,
                    'storage_path' => $storedPath,
                ]);
            }

            $stored++;
            $caId = $this->matchLeadId($inbound, $account);
            $thread = $this->threadService->resolveForInbound($inbound, $account, $caId);

            $inbound->update([
                'email_thread_id' => $thread->id,
                'ca_id' => $caId,
                'match_status' => $caId ? 'matched' : 'unmatched',
                'matched_at' => $caId ? now() : null,
            ]);

            if ($caId) {
                $emailLogId = $this->matchOutboundLogId($inbound);
                if ($emailLogId) {
                    $inbound->update(['email_log_id' => $emailLogId]);
                    $this->markOutboundReplyReceived($inbound, $emailLogId);
                }

                $matched++;

                $this->activityLogService->log(
                    'EMAIL_INBOUND',
                    $direction === EmailInboundMessage::DIRECTION_SENT ? 'Email Sent (IMAP)' : 'Email Reply Received',
                    (string) $caId,
                    ($inbound->subject ?: 'No subject').' · '.$inbound->from_email,
                    'IMAP Sync',
                );

                if ($direction === EmailInboundMessage::DIRECTION_INBOUND) {
                    $this->notifyReply($inbound, $caId);
                }
            }
        }

        if ($maxUid > $sinceUid) {
            $syncState[$folder] = ['last_uid' => $maxUid];
        }

        return $stored;
    }

    private function markOutboundReplyReceived(EmailInboundMessage $inbound, int $emailLogId): void
    {
        $log = EmailLog::query()->find($emailLogId);
        if (! $log) {
            return;
        }

        $preview = Str::limit(strip_tags((string) ($inbound->body_text ?: $inbound->body_html)), 200);

        $log->update([
            'email_status' => EmailRecipientValidationService::STATUS_REPLY_RECEIVED,
            'reply_received_at' => $inbound->received_at ?? now(),
            'reply_from' => $inbound->from_email,
            'reply_preview' => $preview,
        ]);
    }

    private function notifyReply(EmailInboundMessage $inbound, int $caId): void
    {
        $employeeUserId = null;
        $lead = CaMaster::query()->find($caId);
        if ($lead) {
            $employeeId = $lead->leadAssignments()
                ->where('status', 'Active')
                ->value('employee_id');
            if ($employeeId) {
                $employeeUserId = Employee::query()->where('employee_id', $employeeId)->value('user_id');
                $employeeUserId = $employeeUserId ? (int) $employeeUserId : null;
            }
        }

        if (! $employeeUserId && $inbound->email_log_id) {
            $log = EmailLog::query()->find($inbound->email_log_id);
            if ($log?->employee_id) {
                $employeeUserId = Employee::query()->where('employee_id', $log->employee_id)->value('user_id');
                $employeeUserId = $employeeUserId ? (int) $employeeUserId : null;
            }
        }

        $this->notificationService->customerReplyReceived(
            $inbound->from_email,
            (string) $inbound->subject,
            $caId,
            (int) $inbound->id,
            $employeeUserId,
        );
    }

    private function matchLeadId(EmailInboundMessage $inbound, EmailSetting $account): ?int
    {
        if ($inbound->email_log_id) {
            $log = EmailLog::query()->find($inbound->email_log_id);

            return $log?->ca_id ? (int) $log->ca_id : null;
        }

        $replyLog = null;
        $inReplyTo = $this->normalizeMessageId($inbound->in_reply_to);
        if ($inReplyTo) {
            $replyLog = $this->findOutboundLogByMessageId($inReplyTo);
        }

        if (! $replyLog && $inbound->references_header) {
            $refs = preg_split('/\s+/', trim($inbound->references_header)) ?: [];
            foreach (array_reverse($refs) as $ref) {
                $replyLog = $this->findOutboundLogByMessageId($this->normalizeMessageId($ref));
                if ($replyLog) {
                    break;
                }
            }
        }

        if ($replyLog?->ca_id) {
            $inbound->email_log_id = $replyLog->id;

            return (int) $replyLog->ca_id;
        }

        if ($inReplyTo && $inbound->from_email) {
            $replyLog = $this->findOutboundLogBySubjectAndRecipient($inbound);
            if ($replyLog?->ca_id) {
                $inbound->email_log_id = $replyLog->id;

                return (int) $replyLog->ca_id;
            }
        }

        $candidateEmails = array_filter([
            $inbound->direction === EmailInboundMessage::DIRECTION_INBOUND
                ? $inbound->from_email
                : $inbound->to_email,
        ]);

        foreach ($candidateEmails as $email) {
            $normalized = $this->fieldNormalization->normalizeEmail($email);
            $lead = CaMaster::query()
                ->where('normalized_email', $normalized)
                ->orWhere('email_id', 'ilike', $email)
                ->first();

            if ($lead) {
                return (int) $lead->ca_id;
            }
        }

        return null;
    }

    private function matchOutboundLogId(EmailInboundMessage $inbound): ?int
    {
        if ($inbound->email_log_id) {
            return (int) $inbound->email_log_id;
        }

        $inReplyTo = $this->normalizeMessageId($inbound->in_reply_to);
        if ($inReplyTo) {
            $log = $this->findOutboundLogByMessageId($inReplyTo);
            if ($log) {
                return (int) $log->id;
            }
        }

        $log = $this->findOutboundLogBySubjectAndRecipient($inbound);

        return $log?->id ? (int) $log->id : null;
    }

    private function reconcileInboundReplies(EmailSetting $account): int
    {
        $reconciled = 0;

        EmailInboundMessage::query()
            ->where('email_setting_id', $account->id)
            ->where('direction', EmailInboundMessage::DIRECTION_INBOUND)
            ->whereNull('email_log_id')
            ->whereNotNull('ca_id')
            ->orderBy('id')
            ->each(function (EmailInboundMessage $inbound) use (&$reconciled) {
                $emailLogId = $this->matchOutboundLogId($inbound);
                if (! $emailLogId) {
                    return;
                }

                $inbound->update(['email_log_id' => $emailLogId]);
                $this->markOutboundReplyReceived($inbound, $emailLogId);
                $reconciled++;
            });

        return $reconciled;
    }

    private function findOutboundLogByMessageId(string $messageId): ?EmailLog
    {
        $normalized = $this->normalizeMessageId($messageId);
        if (! $normalized) {
            return null;
        }

        return EmailLog::query()
            ->where('message_id', $normalized)
            ->orWhere('message_id', 'like', '%'.$normalized.'%')
            ->orderByDesc('id')
            ->first();
    }

    private function findOutboundLogBySubjectAndRecipient(EmailInboundMessage $inbound): ?EmailLog
    {
        if (! $inbound->from_email) {
            return null;
        }

        $subject = $this->stripReplySubject((string) $inbound->subject);
        $query = EmailLog::query()
            ->whereRaw('LOWER(recipient_email) = ?', [strtolower($inbound->from_email)])
            ->whereIn('email_status', [
                EmailRecipientValidationService::STATUS_SENT,
                EmailRecipientValidationService::STATUS_DELIVERED,
                EmailRecipientValidationService::STATUS_REPLY_RECEIVED,
            ])
            ->orderByDesc('sent_at');

        if ($inbound->ca_id) {
            $query->where('ca_id', $inbound->ca_id);
        }

        if ($subject !== '') {
            $query->where('subject', 'ilike', '%'.$subject.'%');
        }

        return $query->first();
    }

    private function normalizeMessageId(?string $messageId): ?string
    {
        if ($messageId === null) {
            return null;
        }

        $messageId = trim($messageId);
        $messageId = trim($messageId, '<>');

        return $messageId !== '' ? $messageId : null;
    }

    private function stripReplySubject(string $subject): string
    {
        return trim(preg_replace('/^(re|fwd|fw):\s*/i', '', $subject) ?? $subject);
    }

    private function resolveSinceDate(EmailSetting $account): \DateTimeInterface
    {
        $lookback = now()->subDay();

        if ($account->last_imap_sync_at) {
            $since = $account->last_imap_sync_at->copy()->subHour();

            return $since->greaterThan($lookback) ? $since : $lookback;
        }

        return $lookback;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function isDuplicateMessage(EmailSetting $account, array $message, string $folder): bool
    {
        $messageId = (string) ($message['message_id'] ?? '');
        $uid = (int) ($message['uid'] ?? 0);

        if ($messageId !== '' && EmailInboundMessage::query()
            ->where('email_setting_id', $account->id)
            ->where('message_id', $messageId)
            ->exists()) {
            return true;
        }

        if ($uid > 0 && EmailInboundMessage::query()
            ->where('email_setting_id', $account->id)
            ->where('folder', $folder)
            ->where('imap_uid', $uid)
            ->exists()) {
            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function syncResult(
        int $synced,
        int $matched,
        int $fetched,
        int $duplicatesSkipped,
        int $reconciled,
        string $message,
        ?EmailSetting $account = null,
        ?EmailSyncLog $syncLog = null,
        bool $success = true,
    ): array {
        return [
            'success' => $success,
            'synced' => $synced,
            'matched' => $matched,
            'fetched' => $fetched,
            'duplicates_skipped' => $duplicatesSkipped,
            'reconciled' => $reconciled,
            'message' => $message,
            'last_sync_at' => $account?->fresh()?->last_imap_sync_at?->toIso8601String(),
            'sync_log_id' => $syncLog?->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function imapConfigFromSetting(EmailSetting $account): array
    {
        $credentials = $account->resolveImapCredentials();

        return $this->imapConnection->normalizeConfig([
            'imap_host' => $credentials['imap_host'] ?? null,
            'imap_port' => $credentials['imap_port'] ?? null,
            'imap_encryption' => $credentials['imap_encryption'] ?? null,
            'imap_username' => $credentials['imap_username'] ?? null,
            'imap_password' => $credentials['imap_password'] ?? null,
            'from_email' => $credentials['from_email'] ?? $account->from_email,
        ]);
    }
}
