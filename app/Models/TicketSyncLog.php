<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketSyncLog extends Model
{
    public const OPERATION_TICKET_INBOUND = 'ticket_inbound';

    public const OPERATION_TICKET_OUTBOUND = 'ticket_outbound';

    public const OPERATION_ORGANIZATION_LOOKUP = 'organization_lookup';

    public const OPERATION_ORGANIZATION_VERIFY = 'organization_verify';

    public const OPERATION_ACKNOWLEDGEMENT = 'acknowledgement';

    protected $fillable = [
        'support_ticket_id',
        'sync_operation',
        'direction',
        'source_system',
        'correlation_id',
        'mobile_number',
        'organization_number',
        'endpoint',
        'http_method',
        'http_status_code',
        'status',
        'external_ticket_id',
        'idempotency_key',
        'request_payload',
        'response_payload',
        'error_message',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'http_status_code' => 'integer',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'processed_at' => 'datetime',
            'correlation_id' => 'string',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }
}
