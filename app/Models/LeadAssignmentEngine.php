<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeadAssignmentEngine extends Model
{
    use SoftDeletes;

    protected $primaryKey = 'assignment_id';

    protected $fillable = [
        'ca_id',
        'employee_id',
        'assigned_date',
        'assignment_type',
        'rotation_logic_used',
        'priority_score',
        'target_leads',
        'achieved_leads',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'assigned_date' => 'date',
            'priority_score' => 'integer',
            'target_leads' => 'integer',
            'achieved_leads' => 'integer',
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
