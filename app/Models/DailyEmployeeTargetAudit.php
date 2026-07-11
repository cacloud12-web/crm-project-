<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyEmployeeTargetAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'daily_employee_target_id',
        'employee_id',
        'target_date',
        'action',
        'before_values',
        'after_values',
        'changed_by',
        'ip_address',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
            'before_values' => 'array',
            'after_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(DailyEmployeeTarget::class, 'daily_employee_target_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
