<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailLog extends Model
{
    protected $table = 'email_logs';

    protected $fillable = [
        'campaign_id',
        'email_setting_id',
        'ca_id',
        'employee_id',
        'recipient_email',
        'cc',
        'bcc',
        'attachments',
        'subject',
        'body',
        'is_html',
        'email_status',
        'message_id',
        'direction',
        'queued_at',
        'sent_at',
        'delivered_at',
        'opened_at',
        'clicked_at',
        'bounced_at',
        'reply_received_at',
        'reply_from',
        'reply_preview',
        'failed_reason',
        'provider_response',
        'error_message',
        'smtp_error',
    ];

    protected function casts(): array
    {
        return [
            'cc' => 'array',
            'bcc' => 'array',
            'attachments' => 'array',
            'is_html' => 'boolean',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
            'bounced_at' => 'datetime',
            'reply_received_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'campaign_id');
    }

    public function caMaster(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'ca_id', 'ca_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}
