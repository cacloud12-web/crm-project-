<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentHistory extends Model
{
    protected $table = 'assignment_histories';

    protected $fillable = [
        'ca_id',
        'previous_employee_id',
        'new_employee_id',
        'assignment_type',
        'reason',
        'assignment_mode',
        'assigned_by',
        'assigned_at',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
        ];
    }

    public function caMaster(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'ca_id', 'ca_id');
    }

    public function previousEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'previous_employee_id', 'employee_id');
    }

    public function newEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'new_employee_id', 'employee_id');
    }

    public function assignedByEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_by', 'employee_id');
    }
}
