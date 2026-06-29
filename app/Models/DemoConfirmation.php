<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemoConfirmation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SUPERSEDED = 'superseded';

    public const SOURCE_SMS = 'SMS';

    protected $fillable = [
        'lead_id',
        'followup_id',
        'employee_id',
        'demo_date',
        'demo_time',
        'confirmation_status',
        'sms_log_id',
        'customer_reply',
        'confirmation_source',
        'confirmed_at',
        'last_sms_sent_at',
        'is_reschedule',
        'previous_confirmation_id',
    ];

    protected function casts(): array
    {
        return [
            'demo_date' => 'date',
            'confirmed_at' => 'datetime',
            'last_sms_sent_at' => 'datetime',
            'is_reschedule' => 'boolean',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'lead_id', 'ca_id');
    }

    public function followUp(): BelongsTo
    {
        return $this->belongsTo(FollowUp::class, 'followup_id', 'followup_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function smsLog(): BelongsTo
    {
        return $this->belongsTo(SmsLog::class, 'sms_log_id');
    }

    public function previousConfirmation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_confirmation_id');
    }

    public function rescheduleLogs(): HasMany
    {
        return $this->hasMany(DemoRescheduleLog::class, 'demo_confirmation_id');
    }

    public function isPending(): bool
    {
        return $this->confirmation_status === self::STATUS_PENDING;
    }
}
