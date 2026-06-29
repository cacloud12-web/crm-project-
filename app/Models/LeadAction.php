<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadAction extends Model
{
    protected $primaryKey = 'action_id';

    protected $fillable = [
        'ca_id',
        'employee_id',
        'action_type',
        'action_at',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'action_at' => 'datetime',
        ];
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
