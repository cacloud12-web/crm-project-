<?php

namespace App\Services\Email;

use App\Models\EmailInboundMessage;
use App\Models\EmailLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class LeadEmailCommunicationService
{
    public function __construct(
        private readonly EmailInboxService $inboxService,
    ) {}

    /**
     * @return array{timeline: Collection<int, array<string, mixed>>, threads: Collection<int, array<string, mixed>>}
     */
    public function timelineForLead(int $caId): array
    {
        $outbound = EmailLog::query()
            ->with('employee')
            ->where('ca_id', $caId)
            ->orderBy('created_at')
            ->limit(100)
            ->get()
            ->map(fn (EmailLog $log) => [
                'id' => 'out-'.$log->id,
                'channel' => 'email',
                'type' => 'outbound',
                'direction' => 'outbound',
                'actor' => $log->employee?->employee_name ?? 'Employee',
                'actor_role' => 'employee',
                'subject' => $log->subject,
                'body' => $log->body,
                'body_preview' => Str::limit(strip_tags((string) $log->body), 200),
                'status' => $log->email_status,
                'from_email' => null,
                'to_email' => $log->recipient_email,
                'reply_received_at' => $log->reply_received_at?->toIso8601String(),
                'reply_from' => $log->reply_from,
                'reply_preview' => $log->reply_preview,
                'message_id' => $log->message_id ?? null,
                'attachments' => is_array($log->attachments) ? $log->attachments : [],
                'occurred_at' => ($log->sent_at ?? $log->created_at)?->toIso8601String(),
            ]);

        $inbound = EmailInboundMessage::query()
            ->with(['attachments', 'emailLog.employee'])
            ->where('ca_id', $caId)
            ->orderBy('received_at')
            ->limit(100)
            ->get()
            ->map(function (EmailInboundMessage $message) {
                $relatedOutbound = $message->emailLog;

                return [
                    'id' => 'in-'.$message->id,
                    'channel' => 'email',
                    'type' => 'inbound',
                    'direction' => $message->direction === EmailInboundMessage::DIRECTION_SENT ? 'outbound' : 'inbound',
                    'actor' => $message->direction === EmailInboundMessage::DIRECTION_INBOUND
                        ? ($message->from_email ?: 'Customer')
                        : ($relatedOutbound?->employee?->employee_name ?? 'Employee'),
                    'actor_role' => $message->direction === EmailInboundMessage::DIRECTION_INBOUND ? 'customer' : 'employee',
                    'subject' => $message->subject,
                    'body' => $message->body_text ?: $message->body_html,
                    'body_preview' => Str::limit(strip_tags((string) ($message->body_text ?: $message->body_html)), 200),
                    'status' => $message->direction === EmailInboundMessage::DIRECTION_INBOUND ? 'Reply Received' : 'Sent',
                    'from_email' => $message->from_email,
                    'to_email' => $message->to_email,
                    'employee' => $relatedOutbound?->employee?->employee_name,
                    'message_id' => $message->message_id,
                    'in_reply_to' => $message->in_reply_to,
                    'attachments' => $message->attachments->map(fn ($a) => [
                        'id' => $a->id,
                        'filename' => $a->filename,
                        'mime_type' => $a->mime_type,
                        'size_bytes' => $a->size_bytes,
                        'download_url' => $a->storage_path ? '/email-inbox/attachments/'.$a->id : null,
                    ])->values()->all(),
                    'occurred_at' => ($message->received_at ?? $message->created_at)?->toIso8601String(),
                ];
            });

        $timeline = $outbound->concat($inbound)->sortBy('occurred_at')->values();
        $threads = $this->inboxService->threadsForLead($caId);

        return [
            'timeline' => $timeline,
            'threads' => $threads,
        ];
    }
}
