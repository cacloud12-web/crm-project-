<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyEmployeeTarget extends Model
{
    protected $fillable = [
        'employee_id',
        'manager_id',
        'target_date',
        'lead_target',
        'call_target',
        'demo_target',
        'followup_target',
        'email_target',
        'sms_target',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
            'lead_target' => 'integer',
            'call_target' => 'integer',
            'demo_target' => 'integer',
            'followup_target' => 'integer',
            'email_target' => 'integer',
            'sms_target' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id', 'employee_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(DailyEmployeeTargetAudit::class);
    }
}
