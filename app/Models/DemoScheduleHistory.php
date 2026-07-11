<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoScheduleHistory extends Model
{
    public $timestamps = false;

    protected $table = 'demo_schedule_history';

    protected $fillable = [
        'demo_schedule_id',
        'action',
        'old_values',
        'new_values',
        'performed_by_user_id',
        'notes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(DemoSchedule::class, 'demo_schedule_id');
    }
}
