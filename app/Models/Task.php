<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'task_id';

    protected $fillable = [
        'followup_id',
        'ca_id',
        'employee_id',
        'task_type',
        'due_date',
        'due_time',
        'priority',
        'status',
        'task_source',
        'remarks',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function followUp(): BelongsTo
    {
        return $this->belongsTo(FollowUp::class, 'followup_id', 'followup_id');
    }

    public function caMaster(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'ca_id', 'ca_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(FollowUpReminder::class, 'task_id', 'task_id');
    }
}
