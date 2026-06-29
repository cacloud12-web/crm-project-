<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowUpHistory extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'history_id';

    protected $table = 'follow_up_histories';

    protected $fillable = [
        'followup_id',
        'ca_id',
        'employee_id',
        'event_type',
        'outcome',
        'remarks',
        'metadata',
        'performed_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
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
}
