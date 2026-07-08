<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoResult extends Model
{
    protected $fillable = [
        'demo_schedule_id',
        'ca_id',
        'employee_id',
        'result',
        'notes',
        'next_followup_id',
        'created_by_user_id',
    ];

    public function demoSchedule(): BelongsTo
    {
        return $this->belongsTo(DemoSchedule::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'ca_id', 'ca_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}
