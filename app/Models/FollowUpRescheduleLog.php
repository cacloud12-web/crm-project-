<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FollowUpRescheduleLog extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'log_id';

    protected $table = 'follow_up_reschedule_logs';

    protected $fillable = [
        'followup_id',
        'ca_id',
        'old_scheduled_at',
        'new_scheduled_at',
        'reason',
        'changed_by',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'old_scheduled_at' => 'datetime',
            'new_scheduled_at' => 'datetime',
            'changed_at' => 'datetime',
        ];
    }
}
