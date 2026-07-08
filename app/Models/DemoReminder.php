<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoReminder extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'demo_schedule_id',
        'reminder_type',
        'channel',
        'remind_at',
        'status',
        'attempts',
        'sent_at',
        'last_error',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'remind_at' => 'datetime',
            'sent_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function demoSchedule(): BelongsTo
    {
        return $this->belongsTo(DemoSchedule::class);
    }
}
