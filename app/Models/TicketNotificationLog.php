<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketNotificationLog extends Model
{
    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const EVENT_TICKET_CREATED = 'ticket_created';

    public const EVENT_STATUS_CHANGED = 'status_changed';

    public const EVENT_REPLY_ADDED = 'reply_added';

    public const EVENT_TICKET_CLOSED = 'ticket_closed';

    protected $fillable = [
        'support_ticket_id',
        'channel',
        'event_type',
        'recipient_type',
        'recipient_address',
        'status',
        'provider_message_id',
        'payload',
        'error_message',
        'attempt_count',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempt_count' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }
}
