<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoRescheduleLog extends Model
{
    protected $fillable = [
        'demo_confirmation_id',
        'followup_id',
        'lead_id',
        'old_demo_date',
        'old_demo_time',
        'new_demo_date',
        'new_demo_time',
        'changed_by',
        'changed_by_employee_id',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'old_demo_date' => 'date',
            'new_demo_date' => 'date',
        ];
    }

    public function demoConfirmation(): BelongsTo
    {
        return $this->belongsTo(DemoConfirmation::class, 'demo_confirmation_id');
    }

    public function followUp(): BelongsTo
    {
        return $this->belongsTo(FollowUp::class, 'followup_id', 'followup_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'lead_id', 'ca_id');
    }
}
