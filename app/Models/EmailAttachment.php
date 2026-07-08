<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailAttachment extends Model
{
    protected $fillable = [
        'email_inbound_message_id',
        'filename',
        'mime_type',
        'size_bytes',
        'storage_path',
    ];

    public function inboundMessage(): BelongsTo
    {
        return $this->belongsTo(EmailInboundMessage::class, 'email_inbound_message_id');
    }
}
