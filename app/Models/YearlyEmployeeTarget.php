<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class YearlyEmployeeTarget extends Model
{
    protected $fillable = [
        'employee_id',
        'target_year',
        'manager_id',
        'lead_target',
        'call_target',
        'demo_target',
        'followup_target',
        'email_target',
        'sms_target',
        'notes',
        'annual_leave_allowance',
        'allow_negative_leave_balance',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'target_year' => 'integer',
            'manager_id' => 'integer',
            'lead_target' => 'integer',
            'call_target' => 'integer',
            'demo_target' => 'integer',
            'followup_target' => 'integer',
            'email_target' => 'integer',
            'sms_target' => 'integer',
            'annual_leave_allowance' => 'integer',
            'allow_negative_leave_balance' => 'boolean',
        ];
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(EmployeeLeave::class, 'employee_id', 'employee_id')
            ->whereColumn('employee_leaves.target_year', 'yearly_employee_targets.target_year');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id', 'employee_id');
    }

    public function calendarDays(): HasMany
    {
        return $this->hasMany(EmployeeCalendarDay::class, 'yearly_employee_target_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
