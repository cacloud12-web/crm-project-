<?php

namespace App\Services\Email;

use App\Models\EmailInboundMessage;
use App\Models\EmailLog;
use App\Models\EmailSetting;
use App\Models\EmailThread;
use Illuminate\Support\Str;

class EmailThreadService
{
    public function resolveForInbound(EmailInboundMessage $inbound, EmailSetting $account, ?int $caId): EmailThread
    {
        $threadKey = $this->resolveThreadKey($inbound, $caId);
        $subject = $this->normalizeSubject((string) $inbound->subject);
        $participant = $inbound->direction === EmailInboundMessage::DIRECTION_INBOUND
            ? $inbound->from_email
            : ($inbound->to_email ?? '');

        $thread = EmailThread::query()->firstOrCreate(
            [
                'email_setting_id' => $account->id,
                'thread_key' => $threadKey,
            ],
            [
                'ca_id' => $caId,
                'subject' => $subject ?: ($inbound->subject ?? 'No subject'),
                'participant_email' => $participant,
                'message_count' => 0,
                'last_message_at' => $inbound->received_at ?? now(),
            ],
        );

        if ($caId && ! $thread->ca_id) {
            $thread->update(['ca_id' => $caId]);
        }

        $thread->update([
            'message_count' => $thread->messages()->count() + 1,
            'last_message_at' => $inbound->received_at ?? now(),
            'subject' => $thread->subject ?: ($subject ?: 'No subject'),
        ]);

        return $thread->fresh();
    }

    private function resolveThreadKey(EmailInboundMessage $inbound, ?int $caId): string
    {
        if ($inbound->in_reply_to) {
            $parentInbound = EmailInboundMessage::query()
                ->where('message_id', $inbound->in_reply_to)
                ->orWhere('message_id', 'like', '%'.trim($inbound->in_reply_to, '<>').'%')
                ->first();

            if ($parentInbound?->email_thread_id) {
                $parentThread = EmailThread::query()->find($parentInbound->email_thread_id);
                if ($parentThread) {
                    return $parentThread->thread_key;
                }
            }

            $parentLog = EmailLog::query()
                ->where('message_id', $inbound->in_reply_to)
                ->orWhere('message_id', 'like', '%'.trim($inbound->in_reply_to, '<>').'%')
                ->first();

            if ($parentLog) {
                return $this->threadKeyFromSubject($parentLog->subject, $caId ?? $parentLog->ca_id);
            }
        }

        if ($inbound->references_header) {
            $refs = preg_split('/\s+/', trim($inbound->references_header)) ?: [];
            foreach (array_reverse($refs) as $ref) {
                $ref = trim($ref, '<>');
                $log = EmailLog::query()->where('message_id', 'like', '%'.$ref.'%')->first();
                if ($log) {
                    return $this->threadKeyFromSubject($log->subject, $caId ?? $log->ca_id);
                }
            }
        }

        return $this->threadKeyFromSubject((string) $inbound->subject, $caId, $inbound->from_email);
    }

    private function threadKeyFromSubject(string $subject, ?int $caId, ?string $email = null): string
    {
        $normalized = $this->normalizeSubject($subject) ?: 'no-subject';
        $parts = [$normalized];
        if ($caId) {
            $parts[] = 'lead:'.$caId;
        } elseif ($email) {
            $parts[] = 'email:'.strtolower(trim($email));
        }

        return hash('sha256', implode('|', $parts));
    }

    private function normalizeSubject(string $subject): string
    {
        $subject = trim($subject);
        $subject = preg_replace('/^(re|fwd|fw):\s*/i', '', $subject) ?? $subject;
        while (preg_match('/^(re|fwd|fw):\s*/i', $subject)) {
            $subject = preg_replace('/^(re|fwd|fw):\s*/i', '', $subject) ?? $subject;
        }

        return Str::lower(trim($subject));
    }
}
