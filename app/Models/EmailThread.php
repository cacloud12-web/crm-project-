<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailThread extends Model
{
    protected $fillable = [
        'email_setting_id',
        'ca_id',
        'thread_key',
        'subject',
        'participant_email',
        'message_count',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
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

    public function messages(): HasMany
    {
        return $this->hasMany(EmailInboundMessage::class, 'email_thread_id');
    }
}
