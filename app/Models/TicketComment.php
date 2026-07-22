<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketComment extends Model
{
    protected $fillable = [
        'support_ticket_id',
        'user_id',
        'author_name',
        'author_type',
        'comment_type',
        'body',
        'visibility',
        'is_internal',
        'source_system',
        'external_comment_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class, 'ticket_comment_id');
    }
}
