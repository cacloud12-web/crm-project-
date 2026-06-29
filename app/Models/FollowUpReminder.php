<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowUpReminder extends Model
{
    protected $primaryKey = 'reminder_id';

    protected $fillable = [
        'followup_id',
        'task_id',
        'employee_id',
        'reminder_type',
        'remind_at',
        'status',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'remind_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function followUp(): BelongsTo
    {
        return $this->belongsTo(FollowUp::class, 'followup_id', 'followup_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id', 'task_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}
