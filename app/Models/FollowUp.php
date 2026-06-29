<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FollowUp extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'followup_id';

    protected $fillable = [
        'ca_id',
        'employee_id',
        'created_by_user_id',
        'parent_followup_id',
        'followup_type',
        'outcome',
        'remarks',
        'scheduled_date',
        'next_followup_date',
        'status',
        'priority',
        'sequence_step',
        'is_auto_generated',
        'source',
        'is_rescheduled',
        'rescheduled_at',
        'rescheduled_by',
        'reschedule_reason',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'datetime',
            'next_followup_date' => 'datetime',
            'is_auto_generated' => 'boolean',
            'is_rescheduled' => 'boolean',
            'rescheduled_at' => 'datetime',
        ];
    }

    public function parentFollowUp(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_followup_id', 'followup_id');
    }

    public function childFollowUps(): HasMany
    {
        return $this->hasMany(self::class, 'parent_followup_id', 'followup_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'followup_id', 'followup_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(FollowUpHistory::class, 'followup_id', 'followup_id');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(FollowUpReminder::class, 'followup_id', 'followup_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id', 'id');
    }

    public function caMaster(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'ca_id', 'ca_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}
