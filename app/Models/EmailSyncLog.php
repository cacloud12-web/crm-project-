<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailSyncLog extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'email_setting_id',
        'status',
        'messages_fetched',
        'messages_stored',
        'leads_matched',
        'error_message',
        'details',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function emailSetting(): BelongsTo
    {
        return $this->belongsTo(EmailSetting::class);
    }
}
