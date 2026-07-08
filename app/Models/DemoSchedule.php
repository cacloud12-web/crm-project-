<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DemoSchedule extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'ca_id',
        'employee_id',
        'followup_id',
        'call_log_id',
        'demo_at',
        'meeting_link',
        'status',
        'customer_name',
        'firm_name',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'demo_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'ca_id', 'ca_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function followUp(): BelongsTo
    {
        return $this->belongsTo(FollowUp::class, 'followup_id', 'followup_id');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(DemoReminder::class);
    }

    public function result(): HasOne
    {
        return $this->hasOne(DemoResult::class);
    }
}
