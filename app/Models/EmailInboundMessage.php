<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailInboundMessage extends Model
{
    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_SENT = 'sent';

    protected $fillable = [
        'email_setting_id',
        'ca_id',
        'email_log_id',
        'email_thread_id',
        'folder',
        'imap_uid',
        'direction',
        'message_id',
        'in_reply_to',
        'references_header',
        'from_email',
        'to_email',
        'subject',
        'body_text',
        'body_html',
        'received_at',
        'matched_at',
        'is_read',
        'match_status',
        'raw_headers',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'matched_at' => 'datetime',
            'is_read' => 'boolean',
            'raw_headers' => 'array',
        ];
    }

    public function emailSetting(): BelongsTo
    {
        return $this->belongsTo(EmailSetting::class);
    }

    public function caMaster(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'ca_id', 'ca_id');
    }

    public function emailLog(): BelongsTo
    {
        return $this->belongsTo(EmailLog::class);
    }

    public function emailThread(): BelongsTo
    {
        return $this->belongsTo(EmailThread::class, 'email_thread_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class);
    }
}
