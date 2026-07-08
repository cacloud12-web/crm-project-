<?php

namespace App\Services\Email;

use App\Jobs\Email\SyncEmailImapJob;
use App\Models\CaMaster;
use App\Models\EmailInboundMessage;
use App\Models\EmailLog;
use App\Models\EmailSetting;
use App\Models\EmailSyncLog;
use App\Models\EmailThread;
use App\Services\Concerns\SearchesListings;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Support\Queue\QueueDispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EmailInboxService
{
    use SearchesListings;

    public function __construct(
        private readonly EmployeeDataScopeService $employeeDataScope,
        private readonly EmailImapSyncService $imapSyncService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function metrics(): array
    {
        $today = now()->startOfDay();
        $base = EmailInboundMessage::query()
            ->where('direction', EmailInboundMessage::DIRECTION_INBOUND);

        return [
            'inbox_total' => (clone $base)->count(),
            'unread_replies' => (clone $base)->where('is_read', false)->count(),
            'today_replies' => (clone $base)->where('received_at', '>=', $today)->count(),
            'unmatched' => (clone $base)->where('match_status', 'unmatched')->count(),
            'matched' => (clone $base)->where('match_status', 'matched')->count(),
            'reply_received_logs' => EmailLog::query()
                ->where('email_status', EmailRecipientValidationService::STATUS_REPLY_RECEIVED)
                ->count(),
            'last_sync_at' => EmailSetting::query()
                ->where('is_active', true)
                ->max('last_imap_sync_at'),
            'last_sync_log' => $this->latestSyncLogSummary(),
            'sync_in_progress' => $this->isSyncInProgress(),
            'sync_status' => $this->resolveSyncStatus(),
        ];
    }

    public function isSyncInProgress(): bool
    {
        return EmailSyncLog::query()
            ->where('status', EmailSyncLog::STATUS_RUNNING)
            ->where('started_at', '>=', now()->subMinutes(10))
            ->exists();
    }

    public function resolveSyncStatus(): string
    {
        if ($this->isSyncInProgress()) {
            return 'Syncing';
        }

        $log = EmailSyncLog::query()->orderByDesc('id')->first();
        if (! $log) {
            return 'Idle';
        }

        return match ($log->status) {
            EmailSyncLog::STATUS_FAILED => 'Failed',
            EmailSyncLog::STATUS_SUCCESS => 'Completed',
            default => 'Idle',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function queueSyncLatest(): array
    {
        if ($this->isSyncInProgress()) {
            return [
                'success' => true,
                'queued' => false,
                'already_running' => true,
                'message' => 'Inbox sync is already in progress.',
                'metrics' => $this->metrics(),
            ];
        }

        QueueDispatcher::dispatchOrRun(new SyncEmailImapJob('quick'));

        return [
            'success' => true,
            'queued' => true,
            'already_running' => false,
            'message' => 'Inbox sync started.',
            'metrics' => $this->metrics(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function syncLatest(): array
    {
        $result = $this->imapSyncService->syncLatestInbox();

        return array_merge($result, [
            'metrics' => $this->metrics(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestSyncLogSummary(): ?array
    {
        $log = EmailSyncLog::query()->orderByDesc('id')->first();
        if (! $log) {
            return null;
        }

        $details = is_array($log->details) ? $log->details : [];

        return [
            'id' => $log->id,
            'status' => $log->status,
            'messages_fetched' => $log->messages_fetched,
            'messages_stored' => $log->messages_stored,
            'duplicates_skipped' => (int) ($details['duplicates_skipped'] ?? 0),
            'leads_matched' => $log->leads_matched,
            'error_message' => $log->error_message,
            'finished_at' => $log->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function searchInbox(array $params = []): array
    {
        $folder = (string) ($params['folder'] ?? 'inbox');
        $query = EmailInboundMessage::query()
            ->with(['caMaster:ca_id,firm_name,email_id', 'attachments', 'emailThread']);

        if ($folder === 'inbox') {
            $query->where('direction', EmailInboundMessage::DIRECTION_INBOUND);
        } elseif ($folder === 'sent') {
            $query->where('direction', EmailInboundMessage::DIRECTION_SENT);
        } elseif ($folder === 'unmatched') {
            $query->where('direction', EmailInboundMessage::DIRECTION_INBOUND)
                ->where('match_status', 'unmatched');
        }

        if (! empty($params['unread_only'])) {
            $query->where('is_read', false);
        }

        if (! empty($params['ca_id'])) {
            $query->where('ca_id', (int) $params['ca_id']);
        }

        $employeeId = $this->employeeDataScope->scopedEmployeeId(auth()->user());
        if ($employeeId !== null && $employeeId > 0) {
            $leadIds = CaMaster::query()
                ->whereHas('leadAssignments', fn ($q) => $q->where('employee_id', $employeeId)->where('status', 'Active'))
                ->pluck('ca_id');
            $query->where(function ($q) use ($leadIds) {
                $q->whereIn('ca_id', $leadIds)->orWhereNull('ca_id');
            });
        }

        return $this->searchListing($query, $params, 'email_inbox');
    }

    /**
     * @return array<string, mixed>
     */
    public function show(int $id): array
    {
        $message = EmailInboundMessage::query()
            ->with(['attachments', 'caMaster', 'emailLog.employee', 'emailThread'])
            ->findOrFail($id);

        if (! $message->is_read) {
            $message->update(['is_read' => true]);
        }

        $threadMessages = collect();
        if ($message->email_thread_id) {
            $threadMessages = EmailInboundMessage::query()
                ->with(['attachments'])
                ->where('email_thread_id', $message->email_thread_id)
                ->orderBy('received_at')
                ->get()
                ->map(fn (EmailInboundMessage $item) => $this->messageToArray($item));
        }

        $outbound = collect();
        if ($message->email_log_id) {
            $log = EmailLog::query()->with('employee')->find($message->email_log_id);
            if ($log) {
                $outbound = collect([$this->outboundToArray($log)]);
            }
        } elseif ($message->ca_id) {
            $outbound = EmailLog::query()
                ->with('employee')
                ->where('ca_id', $message->ca_id)
                ->where('subject', 'like', '%'.Str::limit($this->stripRe((string) $message->subject), 40, '').'%')
                ->orderBy('created_at')
                ->limit(5)
                ->get()
                ->map(fn (EmailLog $log) => $this->outboundToArray($log));
        }

        return [
            'message' => $this->messageToArray($message),
            'thread' => $threadMessages->values()->all(),
            'outbound' => $outbound->values()->all(),
            'suggest_followup' => $message->direction === EmailInboundMessage::DIRECTION_INBOUND
                && $message->ca_id !== null,
        ];
    }

    public function markRead(int $id, bool $read = true): void
    {
        EmailInboundMessage::query()->where('id', $id)->update(['is_read' => $read]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function threadsForLead(int $caId): Collection
    {
        return EmailThread::query()
            ->with(['messages' => fn ($q) => $q->orderBy('received_at')])
            ->where('ca_id', $caId)
            ->orderByDesc('last_message_at')
            ->get()
            ->map(function (EmailThread $thread) {
                $outbound = EmailLog::query()
                    ->with('employee')
                    ->where('ca_id', $thread->ca_id)
                    ->orderBy('created_at')
                    ->get()
                    ->map(fn (EmailLog $log) => $this->outboundToArray($log));

                $inbound = $thread->messages->map(fn (EmailInboundMessage $m) => $this->messageToArray($m));

                return [
                    'id' => $thread->id,
                    'subject' => $thread->subject,
                    'message_count' => $thread->message_count,
                    'last_message_at' => $thread->last_message_at?->toIso8601String(),
                    'timeline' => $outbound->concat($inbound)->sortBy('occurred_at')->values()->all(),
                ];
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function messageToArray(EmailInboundMessage $message): array
    {
        return [
            'id' => $message->id,
            'type' => 'inbound',
            'direction' => $message->direction,
            'from_email' => $message->from_email,
            'to_email' => $message->to_email,
            'subject' => $message->subject,
            'body' => $message->body_text ?: $message->body_html,
            'body_preview' => Str::limit(strip_tags((string) ($message->body_text ?: $message->body_html)), 200),
            'status' => $message->match_status,
            'is_read' => (bool) $message->is_read,
            'ca_id' => $message->ca_id,
            'lead_name' => $message->caMaster?->firm_name,
            'employee' => $message->emailLog?->employee?->employee_name,
            'occurred_at' => ($message->received_at ?? $message->created_at)?->toIso8601String(),
            'attachments' => $message->attachments->map(fn ($a) => [
                'id' => $a->id,
                'filename' => $a->filename,
                'mime_type' => $a->mime_type,
                'size_bytes' => $a->size_bytes,
                'download_url' => $a->storage_path ? '/email-inbox/attachments/'.$a->id : null,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function outboundToArray(EmailLog $log): array
    {
        return [
            'id' => 'out-'.$log->id,
            'type' => 'outbound',
            'direction' => 'outbound',
            'from_email' => null,
            'to_email' => $log->recipient_email,
            'subject' => $log->subject,
            'body' => $log->body,
            'body_preview' => Str::limit(strip_tags((string) $log->body), 200),
            'status' => $log->email_status,
            'employee' => $log->employee?->employee_name,
            'reply_received_at' => $log->reply_received_at?->toIso8601String(),
            'reply_from' => $log->reply_from,
            'reply_preview' => $log->reply_preview,
            'occurred_at' => ($log->sent_at ?? $log->created_at)?->toIso8601String(),
            'attachments' => [],
        ];
    }

    private function stripRe(string $subject): string
    {
        return trim(preg_replace('/^(re|fwd|fw):\s*/i', '', $subject) ?? $subject);
    }
}
