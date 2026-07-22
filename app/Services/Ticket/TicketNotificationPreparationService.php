<?php

namespace App\Services\Ticket;

use App\Events\Ticket\TicketClosed;
use App\Events\Ticket\TicketCreated;
use App\Events\Ticket\TicketReplyAdded;
use App\Events\Ticket\TicketStatusChanged;
use App\Models\SupportTicket;
use App\Models\TicketComment;
use App\Models\TicketNotificationLog;
use App\Models\User;
use Illuminate\Support\Facades\Event;

class TicketNotificationPreparationService
{
    /**
     * Prepare notification records and dispatch domain events without sending messages.
     */
    public function prepareForTicketCreated(SupportTicket $ticket, ?User $actor = null): void
    {
        $this->queuePendingLogs($ticket, TicketNotificationLog::EVENT_TICKET_CREATED, [
            ['channel' => TicketNotificationLog::CHANNEL_EMAIL, 'recipient_type' => 'client'],
            ['channel' => TicketNotificationLog::CHANNEL_EMAIL, 'recipient_type' => 'admin'],
            ['channel' => TicketNotificationLog::CHANNEL_WHATSAPP, 'recipient_type' => 'client'],
        ]);

        $this->refreshSummaryStatuses($ticket);
        Event::dispatch(new TicketCreated($ticket, $actor));
    }

    public function prepareForStatusChanged(
        SupportTicket $ticket,
        string $fromStatus,
        string $toStatus,
        ?User $actor = null,
    ): void {
        $this->queuePendingLogs($ticket, TicketNotificationLog::EVENT_STATUS_CHANGED, [
            ['channel' => TicketNotificationLog::CHANNEL_EMAIL, 'recipient_type' => 'client'],
            ['channel' => TicketNotificationLog::CHANNEL_EMAIL, 'recipient_type' => 'admin'],
            ['channel' => TicketNotificationLog::CHANNEL_WHATSAPP, 'recipient_type' => 'client'],
        ], [
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
        ]);

        $this->refreshSummaryStatuses($ticket);
        Event::dispatch(new TicketStatusChanged($ticket, $fromStatus, $toStatus, $actor));

        if ($toStatus === 'closed') {
            Event::dispatch(new TicketClosed($ticket, $actor));
        }
    }

    public function prepareForReplyAdded(
        SupportTicket $ticket,
        TicketComment $comment,
        ?User $actor = null,
    ): void {
        if ($comment->is_internal || $comment->visibility === 'internal') {
            return;
        }

        $this->queuePendingLogs($ticket, TicketNotificationLog::EVENT_REPLY_ADDED, [
            ['channel' => TicketNotificationLog::CHANNEL_EMAIL, 'recipient_type' => 'client'],
            ['channel' => TicketNotificationLog::CHANNEL_EMAIL, 'recipient_type' => 'admin'],
            ['channel' => TicketNotificationLog::CHANNEL_WHATSAPP, 'recipient_type' => 'client'],
        ], [
            'comment_id' => $comment->id,
        ]);

        $this->refreshSummaryStatuses($ticket);
        Event::dispatch(new TicketReplyAdded($ticket, $comment, $actor));
    }

    public function prepareForTicketClosed(
        SupportTicket $ticket,
        ?User $actor = null,
    ): void {
        $this->queuePendingLogs($ticket, TicketNotificationLog::EVENT_TICKET_CLOSED, [
            ['channel' => TicketNotificationLog::CHANNEL_EMAIL, 'recipient_type' => 'client'],
            ['channel' => TicketNotificationLog::CHANNEL_EMAIL, 'recipient_type' => 'admin'],
            ['channel' => TicketNotificationLog::CHANNEL_WHATSAPP, 'recipient_type' => 'client'],
        ]);

        $this->refreshSummaryStatuses($ticket);
        Event::dispatch(new TicketClosed($ticket, $actor));
    }

    /**
     * @param  list<array{channel: string, recipient_type: string}>  $recipients
     * @param  array<string, mixed>  $payload
     */
    private function queuePendingLogs(SupportTicket $ticket, string $eventType, array $recipients, array $payload = []): void
    {
        foreach ($recipients as $recipient) {
            TicketNotificationLog::create([
                'support_ticket_id' => $ticket->id,
                'channel' => $recipient['channel'],
                'event_type' => $eventType,
                'recipient_type' => $recipient['recipient_type'],
                'recipient_address' => $this->resolveRecipientAddress($ticket, $recipient['recipient_type'], $recipient['channel']),
                'status' => 'pending',
                'payload' => array_merge($payload, [
                    'ticket_number' => $ticket->ticket_number,
                    'status' => $ticket->status,
                ]),
                'attempt_count' => 0,
            ]);
        }
    }

    private function resolveRecipientAddress(SupportTicket $ticket, string $recipientType, string $channel): string
    {
        if ($recipientType === 'client') {
            return $channel === TicketNotificationLog::CHANNEL_WHATSAPP
                ? (string) $ticket->mobile_number
                : (string) ($ticket->email ?? '');
        }

        return 'admin';
    }

    private function refreshSummaryStatuses(SupportTicket $ticket): void
    {
        $ticket->update([
            'notification_email_status' => 'pending',
            'notification_whatsapp_status' => 'pending',
        ]);
    }
}
